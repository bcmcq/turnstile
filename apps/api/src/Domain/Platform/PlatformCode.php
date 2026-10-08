<?php

declare(strict_types=1);

namespace App\Domain\Platform;

enum PlatformCode: string
{
    case TixHub = 'tixhub';
    case SeatSwap = 'seatswap';
    case PassMarket = 'passmarket';

    public function displayName(): string
    {
        return match ($this) {
            self::TixHub => 'TixHub',
            self::SeatSwap => 'SeatSwap',
            self::PassMarket => 'PassMarket',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TixHub => '#22D3EE',
            self::SeatSwap => '#A78BFA',
            self::PassMarket => '#FBBF24',
        };
    }

    /** Messenger transport name; one queue per platform so a saturated one cannot starve the rest. */
    public function transport(): string
    {
        return 'platform_' . $this->value;
    }
}
