<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Infrastructure\Redis\RedisFactory;

/** Pause/cancel flags that every worker checks before touching a job. Redis so the check costs nothing. */
final class RunFlags
{
    public function __construct(private readonly RedisFactory $redis)
    {
    }

    public function isPaused(string $runId): bool
    {
        return (bool) $this->redis->get()->exists("run:{$runId}:paused");
    }

    public function isCancelled(string $runId): bool
    {
        return (bool) $this->redis->get()->exists("run:{$runId}:cancelled");
    }

    public function pause(string $runId): void
    {
        $this->redis->get()->set("run:{$runId}:paused", '1');
    }

    public function resume(string $runId): void
    {
        $this->redis->get()->del("run:{$runId}:paused");
    }

    public function cancel(string $runId): void
    {
        $redis = $this->redis->get();
        $redis->set("run:{$runId}:cancelled", '1');
        $redis->del("run:{$runId}:paused");
    }
}
