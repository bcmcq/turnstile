<?php

declare(strict_types=1);

namespace App\Domain\Venue;

enum SectionTier: string
{
    case Floor = 'floor';
    case Lower = 'lower';
    case Upper = 'upper';

    public function faceValueCents(): int
    {
        return match ($this) {
            self::Floor => 25_000,
            self::Lower => 12_000,
            self::Upper => 6_500,
        };
    }

    /** Section codes are 1xx for the lower bowl and 2xx for the upper bowl. */
    public function codeBase(): int
    {
        return match ($this) {
            self::Floor => 0,
            self::Lower => 100,
            self::Upper => 200,
        };
    }
}
