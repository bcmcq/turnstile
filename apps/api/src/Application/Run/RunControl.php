<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Application\Job\JobRepository;
use App\Application\Job\Message\ProcessTicketJob;
use App\Application\Platform\PlatformRepository;
use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\RunCounters;
use App\Domain\Job\JobStatus;
use App\Domain\Run\RunStatus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/** Pause, resume, cancel, and the two retry paths. */
final readonly class RunControl
{
    public function __construct(
        private RunRepository $runs,
        private RunFlags $flags,
        private JobRepository $jobs,
        private PlatformRepository $platforms,
        private RunCounters $counters,
        private EventRecorder $events,
        private MessageBusInterface $bus,
    ) {
    }

    public function pause(string $runId): RunRow
    {
        $run = $this->require($runId);
        if (!\in_array($run->status, [RunStatus::Dispatching, RunStatus::Running], true)) {
            throw new RunConflictException("run #{$run->number} is {$run->status->value}, cannot pause");
        }
        $this->flags->pause($runId);
        $this->runs->setStatus($runId, RunStatus::Paused);
        $this->events->push('run.paused', ['runId' => $runId, 'number' => $run->number]);

        return $this->require($runId);
    }

    public function resume(string $runId): RunRow
    {
        $run = $this->require($runId);
        if (RunStatus::Paused !== $run->status) {
            throw new RunConflictException("run #{$run->number} is {$run->status->value}, cannot resume");
        }
        $this->flags->resume($runId);
        $this->runs->setStatus($runId, RunStatus::Running);
        $this->events->push('run.resumed', ['runId' => $runId, 'number' => $run->number]);

        return $this->require($runId);
    }

    public function cancel(string $runId): RunRow
    {
        $run = $this->require($runId);
        if (!$run->status->isActive()) {
            throw new RunConflictException("run #{$run->number} is already {$run->status->value}");
        }
        $this->flags->cancel($runId);
        $this->runs->setStatus($runId, RunStatus::Cancelled);
        $this->events->push('run.cancelled', ['runId' => $runId, 'number' => $run->number]);

        return $this->require($runId);
    }

    /** @return int jobs re-queued */
    public function retryFailed(string $runId): int
    {
        $run = $this->require($runId);
        $ids = $this->jobs->idsByStatus($runId, JobStatus::DeadLettered);
        foreach ($ids as $id) {
            $this->requeue($id, $run);
        }

        return \count($ids);
    }

    public function retryJob(int $jobId): void
    {
        $job = $this->jobs->find($jobId) ?? throw new RunConflictException("job {$jobId} not found");
        if (JobStatus::DeadLettered !== $job->status) {
            throw new RunConflictException("job {$jobId} is {$job->status->value}, only dead-lettered jobs can be retried");
        }
        $this->requeue($jobId, $this->require($job->runId));
    }

    private function requeue(int $jobId, RunRow $run): void
    {
        $job = $this->jobs->find($jobId);
        if (null === $job) {
            return;
        }
        $this->jobs->resetForRetry($jobId);
        $this->counters->incr($run->id, 'dead_lettered', -1);
        if (!$run->status->isActive()) {
            $this->runs->setStatus($run->id, RunStatus::Running);
            $this->flags->resume($run->id);
        }
        $platform = null === $job->platformId ? null : $this->platforms->byId($job->platformId)->code;
        $message = new ProcessTicketJob($jobId, $run->id, $job->ticketId, $platform);
        $this->bus->dispatch($message, [new TransportNamesStamp([$message->transport()])]);
        $this->events->push('job.requeued', ['jobId' => $jobId, 'runId' => $run->id, 'ticketId' => $job->ticketId]);
    }

    private function require(string $runId): RunRow
    {
        return $this->runs->find($runId) ?? throw new RunConflictException("run {$runId} not found");
    }
}
