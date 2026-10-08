<?php

declare(strict_types=1);

namespace App\Application\Realtime\Dto;

use App\Application\Run\Dto\RunView;
use App\Application\Scaling\AutoscaleState;

/** Everything the metrics column, platform gauges and worker cards need. Published 1–4×/s. */
final readonly class MetricsSnapshot
{
    /**
     * @param array<string, int>             $counts    status counts of the current run
     * @param array<string, list<int|float>> $series    60 one-second samples per metric, oldest first
     * @param list<PlatformGauge>            $platforms
     * @param list<WorkerView>               $workers
     */
    public function __construct(
        public int $ts,
        public ?RunView $run,
        public float $jobsPerSec,
        public int $eventsPerSec,
        public array $counts,
        public array $series,
        public array $platforms,
        public array $workers,
        public AutoscaleState $autoscale,
        public bool $paceToVendorLimit,
    ) {
    }
}
