<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Infrastructure\Redis\RedisFactory;

/**
 * Each worker keeps a short-lived hash in Redis (TTL 10 s) with what it is doing, plus a sorted set of
 * completion timestamps for the jobs/min window. The dashboard's worker cards are built from these.
 */
final class WorkerHeartbeat
{
    private const int TTL_SECONDS = 10;
    private const int WINDOW_SECONDS = 60;

    public function __construct(private readonly RedisFactory $redis, private readonly string $workerId)
    {
    }

    public function register(): void
    {
        $this->redis->get()->hMSet("worker:{$this->workerId}", ['id' => $this->workerId, 'pid' => (string) getmypid(), 'started_at' => (string) time(), 'state' => 'idle']);
        $this->touch();
    }

    public function deregister(): void
    {
        $redis = $this->redis->get();
        $redis->del("worker:{$this->workerId}");
        $redis->del("worker:{$this->workerId}:completions");
    }

    public function claim(int $jobId, int $ticketId, string $platform): void
    {
        $this->redis->get()->hMSet("worker:{$this->workerId}", ['state' => 'busy', 'job_id' => (string) $jobId, 'ticket_id' => (string) $ticketId, 'platform' => $platform, 'claimed_at' => (string) (int) (microtime(true) * 1000)]);
        $this->touch();
    }

    public function release(string $state, ?int $latencyMs): void
    {
        $fields = ['state' => $state, 'job_id' => '', 'ticket_id' => ''];
        if (null !== $latencyMs) {
            $fields['last_latency_ms'] = (string) $latencyMs;
        }
        $this->redis->get()->hMSet("worker:{$this->workerId}", $fields);
        $this->touch();
    }

    public function completed(): void
    {
        $redis = $this->redis->get();
        $now = microtime(true);
        $key = "worker:{$this->workerId}:completions";
        $redis->zAdd($key, $now, (string) $now);
        $redis->zRemRangeByScore($key, '-inf', (string) ($now - self::WINDOW_SECONDS));
        $redis->expire($key, self::WINDOW_SECONDS * 2);
    }

    private function touch(): void
    {
        $this->redis->get()->expire("worker:{$this->workerId}", self::TTL_SECONDS);
    }
}
