<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Application\Job\Message\ProcessTicketJob;
use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\RunCounters;
use App\Application\Run\Message\RunStarted;
use App\Application\Run\RunConflictException;
use App\Application\Run\RunControl;
use App\Application\Run\RunFinalizer;
use App\Domain\Job\JobOutcome;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** Messenger decides whether a failure retries; this is where "no more retries" becomes a dead letter. */
final class JobLifecycleListener
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly RunCounters $counters,
        private readonly EventRecorder $events,
        private readonly RunFinalizer $finalizer,
        private readonly RunControl $control,
    ) {
    }

    #[AsEventListener]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($message instanceof RunStarted) {
            // A fan-out that died mid-way left the run "dispatching" with a partial job set; a retry would return
            // early and the run would block every new one. Cancel it so the dispatched jobs drain and the UI moves on.
            try {
                $this->control->cancel($message->runId);
            } catch (RunConflictException) {
                // already terminal
            }

            return;
        }
        if (!$message instanceof ProcessTicketJob || $event->willRetry()) {
            return;
        }
        $job = $this->jobs->find($message->jobId);
        if (null === $job) {
            return;
        }
        if ($job->status->isTerminal()) {
            $this->finalizer->check($job->runId);

            return;
        }
        $throwable = $event->getThrowable();
        $cause = $throwable instanceof HandlerFailedException ? ($throwable->getWrappedExceptions()[0] ?? $throwable) : $throwable;
        $outcome = $job->lastOutcome ?? JobOutcome::Unexpected;

        $this->jobs->markDeadLettered($job->id, $outcome, $cause->getMessage());
        $this->counters->incr($job->runId, 'dead_lettered');
        $this->events->push('job.dead_lettered', ['jobId' => $job->id, 'runId' => $job->runId, 'ticketId' => $job->ticketId, 'outcome' => $outcome->value, 'error' => mb_substr($cause->getMessage(), 0, 200), 'attempts' => $job->attempts]);
        $this->finalizer->check($job->runId);
    }
}
