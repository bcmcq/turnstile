<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Job\JobOutcome;

enum PlatformFailure
{
    case RateLimited;
    case ServerError;
    case Timeout;
    case ListingSold;
    case Rejected;

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::ServerError, self::Timeout => true,
            self::ListingSold, self::Rejected => false,
        };
    }

    public function outcome(): JobOutcome
    {
        return match ($this) {
            self::RateLimited => JobOutcome::Http429,
            self::ServerError => JobOutcome::Http5xx,
            self::Timeout => JobOutcome::Timeout,
            self::ListingSold => JobOutcome::SoldDuringRun,
            self::Rejected => JobOutcome::Unexpected,
        };
    }
}
