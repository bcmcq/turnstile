<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Domain\Job\JobOutcome;
use App\Domain\Job\JobStatus;

final readonly class JobRow
{
    public function __construct(
        public int $id,
        public string $runId,
        public int $ticketId,
        public ?int $platformId,
        public JobStatus $status,
        public int $attempts,
        public int $maxAttempts,
        public ?JobOutcome $lastOutcome,
        public ?string $lastError,
        public ?string $workerId,
        public ?int $durationMs,
    ) {
    }
}
