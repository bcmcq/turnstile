<?php

declare(strict_types=1);

namespace App\Application\Job;

/** One row of the "#1 429 → +1s → #2 …" trace on the Failed tab. */
final readonly class JobAttemptRow
{
    public function __construct(
        public int $attemptNo,
        public string $workerId,
        public ?string $outcome,
        public ?int $httpStatus,
        public ?int $latencyMs,
        public ?int $retryAfterMs,
        public ?string $error,
        public string $startedAt,
        public ?string $finishedAt,
    ) {
    }
}
