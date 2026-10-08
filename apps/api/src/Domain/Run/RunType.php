<?php

declare(strict_types=1);

namespace App\Domain\Run;

enum RunType: string
{
    case Fill = 'fill';
    case Transfer = 'transfer';
    case Release = 'release';
    case Reprice = 'reprice';
    case Regenerate = 'regenerate';
    case CloseSection = 'close_section';
    case OpenSection = 'open_section';
    case Replay = 'replay';

    public function callsPlatform(): bool
    {
        return match ($this) {
            self::Fill, self::OpenSection, self::Replay => false,
            default => true,
        };
    }

    public function requiresTargetPlatform(): bool
    {
        return self::Transfer === $this;
    }
}
