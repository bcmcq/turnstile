<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\WorkerHeartbeat;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/** Worker cards appear and disappear from these heartbeats. */
final class WorkerLifecycleListener
{
    private float $lastTouch = 0.0;

    public function __construct(private readonly WorkerHeartbeat $heartbeat, private readonly EventRecorder $events, private readonly string $workerId)
    {
    }

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->heartbeat->register();
        $this->events->push('worker.joined', ['worker' => $this->workerId]);
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        // Keep the TTL alive on every loop (cancelled messages never claim the heartbeat); cap at once per 3 s.
        if (microtime(true) - $this->lastTouch > 3.0) {
            $this->heartbeat->touch($event->isWorkerIdle() ? 'idle' : null);
            $this->lastTouch = microtime(true);
        }
    }

    #[AsEventListener]
    public function onStopped(WorkerStoppedEvent $event): void
    {
        $this->heartbeat->deregister();
        $this->events->push('worker.left', ['worker' => $this->workerId]);
    }
}
