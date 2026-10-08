<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Application\Realtime\Dto\WorkerView;
use App\Infrastructure\Redis\RedisFactory;

/** Reads live worker heartbeats. Names are "worker-N" by order of first appearance, stable across restarts of the same host. */
final class WorkerRegistry
{
    public function __construct(private readonly RedisFactory $redis)
    {
    }

    /** @return list<WorkerView> */
    public function all(): array
    {
        $redis = $this->redis->get();
        $prefix = 'turnstile:';
        $keys = [];
        $it = null;
        do {
            /** @var array<int, string>|false $batch */
            $batch = $redis->scan($it, $prefix . 'worker:*', 200);
            foreach (\is_array($batch) ? $batch : [] as $k) {
                if (!str_ends_with($k, ':completions') && !str_ends_with($k, ':names')) {
                    $keys[] = substr($k, \strlen($prefix));
                }
            }
        } while ($it > 0);

        $now = microtime(true);
        $workers = [];
        foreach ($keys as $key) {
            /** @var array<string, string>|false $h */
            $h = $redis->hGetAll($key);
            if (!\is_array($h) || !isset($h['id'])) {
                continue;
            }
            $id = $h['id'];
            $workers[] = new WorkerView(
                id: $id,
                name: 'worker-' . $this->number($id),
                state: $h['state'] ?? 'idle',
                jobId: '' !== ($h['job_id'] ?? '') ? (int) $h['job_id'] : null,
                ticketId: '' !== ($h['ticket_id'] ?? '') ? (int) $h['ticket_id'] : null,
                platform: '' !== ($h['platform'] ?? '') ? $h['platform'] : null,
                lastLatencyMs: isset($h['last_latency_ms']) ? (int) $h['last_latency_ms'] : null,
                jobsPerMin: (int) $redis->zCount("worker:{$id}:completions", (string) ($now - 60), '+inf'),
                startedAt: (int) ($h['started_at'] ?? 0),
            );
        }
        usort($workers, static fn (WorkerView $a, WorkerView $b): int => strnatcmp($a->name, $b->name));

        return $workers;
    }

    private function number(string $id): int
    {
        $redis = $this->redis->get();
        $n = $redis->hGet('worker:names', $id);
        if (\is_string($n) && '' !== $n) {
            return (int) $n;
        }
        $n = (int) $redis->incr('worker:names:seq');
        $redis->hSetNx('worker:names', $id, (string) $n);

        return (int) $redis->hGet('worker:names', $id);
    }
}
