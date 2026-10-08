<?php

declare(strict_types=1);

namespace App\Domain\Venue;

/**
 * How densely the arena was seeded; stored on the venue so the dashboard sizes its dots from what is in the
 * database, not from the ARENA_SIZE env the seeder happened to run with. Presets live in ArenaGeometry::for().
 */
enum ArenaSize: string
{
    case Demo = 'demo';
    case Full = 'full';

    /** Seat spacing along a ring, in arena units. */
    public function seatPitch(): float
    {
        return match ($this) {
            self::Demo => 5.4,
            self::Full => 1.7,
        };
    }
}
