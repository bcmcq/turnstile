<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Application\Job\Applier\ApplierRegistry;
use App\Application\Job\Exception\LockConflictException;
use App\Application\Job\Exception\PlatformRejectedException;
use App\Application\Job\Exception\PlatformRetryableException;
use App\Application\Job\Message\ProcessTicketJob;
use App\Application\Platform\ClientRateLimiter;
use App\Application\Platform\PlatformApiException;
use App\Application\Platform\PlatformClientRegistry;
use App\Application\Platform\PlatformFailure;
use App\Application\Platform\PlatformRepository;
use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\PlatformStats;
use App\Application\Realtime\RunCounters;
use App\Application\Realtime\WorkerHeartbeat;
use App\Application\Run\RunFinalizer;
use App\Application\Run\RunFlags;
use App\Application\Run\RunRepository;
use App\Domain\Job\ActionState;
use App\Domain\Job\JobOutcome;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The core of Turnstile. One ticket, one action, with every guarantee the README promises:
 *  0. pause/cancel flags            → park or drop without counting an attempt
 *  1. heartbeat + attempt row       → the worker cards and the retry trace
 *  2. ledger claim                  → idempotency on our side (a replay stops here)
 *  3. rate limiter                  → client-side pacing shared by all workers
 *  4. platform call                 → with the deterministic Idempotency-Key (idempotency on their side)
 *  5. version-checked UPDATE        → optimistic lock; 0 rows = someone else wrote, retry
 *  6. ledger apply + counters       → the row is now "applied"; events flow to the dashboard
 */
#[AsMessageHandler]
final class ProcessTicketJobHandler
{
    private const int PAUSE_PARK_MS = 2_000;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly TicketRepository $tickets,
        private readonly RunRepository $runs,
        private readonly RunFlags $flags,
        private readonly Ledger $ledger,
        private readonly ClientRateLimiter $limiter,
        private readonly PlatformClientRegistry $clients,
        private readonly PlatformRepository $platforms,
        private readonly ApplierRegistry $appliers,
        private readonly WorkerHeartbeat $heartbeat,
        private readonly RunCounters $counters,
        private readonly EventRecorder $events,
        private readonly PlatformStats $platformStats,
        private readonly RunFinalizer $finalizer,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly string $workerId,
    ) {
    }

    public function __invoke(ProcessTicketJob $message): void
    {
        // 0. Run-level flags, no DB hit.
        if ($this->flags->isCancelled($message->runId)) {
            $this->jobs->markCancelled($message->jobId);
            $this->counters->incr($message->runId, 'cancelled');
            $this->finalizer->check($message->runId);

            return;
        }
        if ($this->flags->isPaused($message->runId)) {
            $this->bus->dispatch($message, [new TransportNamesStamp([$message->transport()]), new DelayStamp(self::PAUSE_PARK_MS)]);

            return;
        }

        $job = $this->jobs->find($message->jobId);
        $run = $this->runs->find($message->runId);
        $ticket = $this->tickets->find($message->ticketId);
        if (null === $job || null === $run || null === $ticket) {
            throw new UnrecoverableMessageHandlingException(\sprintf('job %d / run %s / ticket %d not found', $message->jobId, $message->runId, $message->ticketId));
        }
        if ($job->status->isTerminal()) {
            return; // redelivered after completion; nothing to do
        }

        // A replay re-runs the original action against the original run's ledger so every job hits the guard.
        $action = $run->actionType();
        $ledgerRunId = $run->replayOfId ?? $run->id;
        $platformCode = $message->platform;

        // 1. Heartbeat + attempt.
        $this->heartbeat->claim($job->id, $ticket->id, null === $platformCode ? 'house' : $platformCode->value);
        $attemptNo = $this->jobs->openAttempt($job->id, $this->workerId);
        $this->events->push('job.started', ['jobId' => $job->id, 'runId' => $run->id, 'ticketId' => $ticket->id, 'sectionId' => $ticket->sectionId, 'attempt' => $attemptNo, 'worker' => $this->workerId, 'platform' => $platformCode?->value]);
        if ($attemptNo > 1) {
            $this->counters->incr($run->id, 'retries');
        }
        $started = hrtime(true);
        $latencyMs = null;
        $mark = $started;
        $steps = [];
        $step = static function (string $name) use (&$mark, &$steps): void {
            $now = hrtime(true);
            $steps[$name] = (int) (($now - $mark) / 1e6);
            $mark = $now;
        };

        try {
            // 2. Ledger claim.
            $key = Ledger::idempotencyKey($ledgerRunId, $ticket->id, $action);
            $claim = $this->ledger->claim($ticket->id, $ledgerRunId, $job->id, $action, $key);
            $step('ledger');
            if (ActionState::Applied === $claim->state) {
                $this->skip($job->id, $run->id, $ticket, $attemptNo, JobOutcome::IdempotentSkip, 'already applied (idempotency key)');

                return;
            }
            if ($ticket->status->isTerminal()) {
                $this->ledger->release($claim->id);
                $this->skip($job->id, $run->id, $ticket, $attemptNo, JobOutcome::SoldDuringRun, 'ticket sold before the action applied');

                return;
            }

            // 3. Pace ourselves.
            if (null !== $platformCode) {
                $this->limiter->acquire($platformCode);
            }
            $step('limiter');

            // 4. Platform call(s).
            $change = $this->appliers->for($action)->apply(new JobContext($run, $ticket, $key, $this->clients, $this->platforms));
            $step('platform');
            $latencyMs = $steps['platform'];
            if (null !== $platformCode) {
                $this->platformStats->incr($platformCode, 'calls');
                $this->platformStats->incr($platformCode, 'ok');
            }

            // 5. Optimistic lock.
            if (!$change->isNoop() && !$this->tickets->apply($ticket, $change, $run->id)) {
                $this->counters->incr($run->id, 'conflicts');
                throw new LockConflictException($ticket->id, $ticket->version);
            }

            // 6. Applied.
            $step('ticket');
            $this->ledger->apply($claim->id, $ticket, $change);
            $durationMs = (int) ((hrtime(true) - $started) / 1e6);
            $this->jobs->closeAttempt($job->id, $attemptNo, JobOutcome::Success, 200, $latencyMs, null, null);
            $this->jobs->markCompleted($job->id, $durationMs);
            $this->counters->incr($run->id, 'completed');
            $this->heartbeat->release('idle', $latencyMs);
            $this->heartbeat->completed();
            $this->events->push('ticket.updated', ['ticketId' => $ticket->id, 'sectionId' => $ticket->sectionId, 'state' => ($change->status ?? $ticket->status)->code(), 'platformId' => null === $change->listing ? $ticket->platformId : $change->listing['platformId'], 'priceCents' => $change->priceCents ?? $ticket->priceCents, 'runId' => $run->id]);
            $this->events->push('job.completed', ['jobId' => $job->id, 'runId' => $run->id, 'ticketId' => $ticket->id, 'attempt' => $attemptNo, 'worker' => $this->workerId, 'durationMs' => $durationMs, 'latencyMs' => $latencyMs]);
            $this->finalizer->check($run->id);
            $step('bookkeeping');
            if ($durationMs > 2_000) {
                $this->logger->warning('slow job {job}: {steps}', ['job' => $job->id, 'steps' => json_encode($steps)]);
            }
        } catch (PlatformApiException $e) {
            if (null !== $platformCode) {
                $this->platformStats->incr($platformCode, 'calls');
                $this->platformStats->incr($platformCode, match ($e->failure) {
                    PlatformFailure::RateLimited => 'http_429',
                    PlatformFailure::ServerError => 'http_5xx',
                    PlatformFailure::Timeout => 'timeouts',
                    default => 'rejected',
                });
            }
            $this->failAttempt($job->id, $run->id, $ticket, $attemptNo, $job->maxAttempts, $e->failure->outcome(), $e->httpStatus, $e->latencyMs, $e->retryAfterMs, $e->getMessage(), $e->failure->isRetryable());
            if (PlatformFailure::ListingSold === $e->failure) {
                // The marketplace already sold it; our webhook will (or did) mark it. Skip, do not retry.
                $this->jobs->markSkipped($job->id, JobOutcome::SoldDuringRun, $e->getMessage());
                $this->counters->incr($run->id, 'skipped');
                $this->counters->incr($run->id, 'sold_during_run');
                $this->finalizer->check($run->id);

                return;
            }
            throw $e->failure->isRetryable() ? new PlatformRetryableException($e) : new PlatformRejectedException($e);
        } catch (LockConflictException $e) {
            $this->failAttempt($job->id, $run->id, $ticket, $attemptNo, $job->maxAttempts, JobOutcome::LockConflict, null, $latencyMs, null, $e->getMessage(), true);
            throw $e;
        } catch (UnrecoverableMessageHandlingException $e) {
            $this->failAttempt($job->id, $run->id, $ticket, $attemptNo, $job->maxAttempts, JobOutcome::Unexpected, null, $latencyMs, null, $e->getMessage(), false);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('job {job} attempt {attempt} crashed: {error}', ['job' => $job->id, 'attempt' => $attemptNo, 'error' => $e->getMessage(), 'exception' => $e]);
            $this->failAttempt($job->id, $run->id, $ticket, $attemptNo, $job->maxAttempts, JobOutcome::Unexpected, null, $latencyMs, null, $e->getMessage(), true);
            throw $e;
        }
    }

    private function skip(int $jobId, string $runId, TicketRow $ticket, int $attemptNo, JobOutcome $outcome, string $reason): void
    {
        $this->jobs->closeAttempt($jobId, $attemptNo, $outcome, null, null, null, $reason);
        $this->jobs->markSkipped($jobId, $outcome, $reason);
        $this->counters->incr($runId, 'skipped');
        if (JobOutcome::SoldDuringRun === $outcome) {
            $this->counters->incr($runId, 'sold_during_run');
        }
        $this->heartbeat->release('idle', null);
        $this->events->push('job.skipped', ['jobId' => $jobId, 'runId' => $runId, 'ticketId' => $ticket->id, 'sectionId' => $ticket->sectionId, 'outcome' => $outcome->value, 'reason' => $reason, 'worker' => $this->workerId]);
        $this->finalizer->check($runId);
    }

    private function failAttempt(int $jobId, string $runId, TicketRow $ticket, int $attemptNo, int $maxAttempts, JobOutcome $outcome, ?int $httpStatus, ?int $latencyMs, ?int $retryAfterMs, string $error, bool $willRetry): void
    {
        $this->jobs->closeAttempt($jobId, $attemptNo, $outcome, $httpStatus, $latencyMs, $retryAfterMs, $error);
        $this->heartbeat->release($willRetry ? 'backoff' : 'idle', $latencyMs);
        if ($willRetry && $attemptNo < $maxAttempts) {
            // Mirror of JitteredRetryStrategy's schedule for the UI countdown; the strategy owns the real delay.
            $delayMs = $retryAfterMs ?? (int) min(8_000, 1_000 * (2 ** ($attemptNo - 1)));
            $this->jobs->markRetryWait($jobId, $outcome, $error, $delayMs);
            $this->events->push('job.retry', ['jobId' => $jobId, 'runId' => $runId, 'ticketId' => $ticket->id, 'sectionId' => $ticket->sectionId, 'attempt' => $attemptNo, 'outcome' => $outcome->value, 'error' => $error, 'delayMs' => $delayMs, 'worker' => $this->workerId]);
        }
        // Final failures are marked dead-lettered by JobLifecycleListener once Messenger confirms no retry.
    }
}
