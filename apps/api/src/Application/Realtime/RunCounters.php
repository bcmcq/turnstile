<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Infrastructure\Redis\RedisFactory;

/** Per-run counters live in a Redis hash; RunFinalizer writes the job counts to the runs row once the run ends. */
final class RunCounters
{
    /** Monotonic event counts. Status counts (queued, in_flight, retry_wait, …) come from the jobs table. */
    public const array FIELDS = ['completed', 'skipped', 'dead_lettered', 'cancelled', 'conflicts', 'sold_during_run', 'retries', 'webhook_conflicts'];

    public function __construct(private readonly RedisFactory $redis)
    {
    }

    public function incr(string $runId, string $field, int $by = 1): int
    {
        return (int) $this->redis->get()->hIncrBy("run:{$runId}:counters", $field, $by);
    }

    /** @return array<string, int> */
    public function all(string $runId): array
    {
        /** @var array<string, string>|false $h */
        $h = $this->redis->get()->hGetAll("run:{$runId}:counters");
        $out = array_fill_keys(self::FIELDS, 0);
        foreach (\is_array($h) ? $h : [] as $k => $v) {
            $out[$k] = (int) $v;
        }

        return $out;
    }

    public function reset(string $runId): void
    {
        $this->redis->get()->del("run:{$runId}:counters");
    }
}
