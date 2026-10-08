<?php

declare(strict_types=1);

namespace App\Application\Realtime\Dto;

final readonly class WorkerView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $state,
        public ?int $jobId,
        public ?int $ticketId,
        public ?string $platform,
        public ?int $lastLatencyMs,
        public int $jobsPerMin,
        public int $startedAt,
    ) {
    }
}
