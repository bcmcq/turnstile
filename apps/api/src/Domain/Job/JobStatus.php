<?php

declare(strict_types=1);

namespace App\Domain\Job;

enum JobStatus: string
{
    case Queued = 'queued';
    case InFlight = 'in_flight';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case RetryWait = 'retry_wait';
    case DeadLettered = 'dead_lettered';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Skipped, self::DeadLettered, self::Cancelled => true,
            default => false,
        };
    }
}
