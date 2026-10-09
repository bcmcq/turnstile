<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Application\Job\JobRepository;
use App\Application\Platform\ClientRateLimiter;
use App\Application\Platform\PlatformRepository;
use App\Application\Realtime\Dto\MetricsSnapshot;
use App\Application\Realtime\Dto\PlatformGauge;
use App\Application\Run\RunRepository;
use App\Application\Run\RunViewFactory;
use App\Application\Scaling\AutoscalePolicy;
use Doctrine\DBAL\Connection;

/** Assembles a MetricsSnapshot from MySQL, Redis and the limiter state. Used by the publisher and GET /api/metrics. */
final class SnapshotBuilder
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly RunViewFactory $runViews,
        private readonly JobRepository $jobs,
        private readonly PlatformRepository $platforms,
        private readonly PlatformStats $platformStats,
        private readonly ClientRateLimiter $limiter,
        private readonly WorkerRegistry $workers,
        private readonly Connection $db,
        private readonly AutoscalePolicy $autoscale,
    ) {
    }

    /** @param array<string, list<int|float>> $series */
    public function build(float $jobsPerSec, int $eventsPerSec, array $series): MetricsSnapshot
    {
        $run = $this->runs->active() ?? $this->runs->latest();
        $counts = null === $run ? array_fill_keys(['queued', 'in_flight', 'retry_wait', 'completed', 'skipped', 'dead_lettered', 'cancelled'], 0) : $this->jobs->countsByStatus($run->id);

        /** @var array<string, int|string> $listed */
        $listed = $this->db->fetchAllKeyValue("SELECT p.code, COUNT(*) FROM tickets t JOIN platforms p ON p.id = t.platform_id WHERE t.status = 'listed' GROUP BY p.code");
        $gauges = [];
        foreach ($this->platforms->all() as $p) {
            $s = $this->platformStats->all($p->code);
            $gauges[] = new PlatformGauge(
                code: $p->code->value,
                name: $p->code->displayName(),
                color: $p->code->color(),
                remainingTokens: max(0, $this->limiter->remaining($p->code)),
                capacity: $this->limiter->capacity($p->code),
                tokensPerSec: round($this->limiter->tokensPerSec($p->code), 2),
                rateLimitPerMin: $p->rateLimitPerMin,
                calls: $s['calls'],
                ok: $s['ok'],
                http429: $s['http_429'],
                http5xx: $s['http_5xx'],
                timeouts: $s['timeouts'],
                rejected: $s['rejected'],
                failureRate: $p->failureRate,
                buyerRate: $p->buyerRate,
                listed: (int) ($listed[$p->code->value] ?? 0),
            );
        }

        return new MetricsSnapshot(
            ts: (int) (microtime(true) * 1000),
            run: null === $run ? null : $this->runViews->make($run),
            jobsPerSec: $jobsPerSec,
            eventsPerSec: $eventsPerSec,
            counts: $counts,
            series: $series,
            platforms: $gauges,
            workers: $this->workers->all(),
            autoscale: $this->autoscale->state(),
            paceToVendorLimit: $this->limiter->followsVendorLimit(),
        );
    }
}
