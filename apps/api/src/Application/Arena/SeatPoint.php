<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Domain\Venue\SeatType;

/** One generated seat, before it has a database id. Coordinates are logical canvas units. */
final readonly class SeatPoint
{
    public function __construct(
        public int $sectionIndex,
        public int $ring,
        public string $rowLabel,
        public int $seatNumber,
        public SeatType $type,
        public float $x,
        public float $y,
    ) {
    }
}
