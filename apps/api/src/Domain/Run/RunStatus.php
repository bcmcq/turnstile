<?php

declare(strict_types=1);

namespace App\Domain\Run;

enum RunStatus: string
{
    case Pending = 'pending';
    case Dispatching = 'dispatching';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case CompletedWithFailures = 'completed_with_failures';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return match ($this) {
            self::Pending, self::Dispatching, self::Running, self::Paused => true,
            default => false,
        };
    }
}
