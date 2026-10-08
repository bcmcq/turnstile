<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Domain\Venue\SectionTier;

/**
 * @phpstan-import-type SectionGeometry from \App\Domain\Venue\Section
 */
final readonly class SectionShape
{
    public function __construct(
        public string $code,
        public SectionTier $tier,
        public float $angleStart,
        public float $angleEnd,
        public int $ringStart,
        public int $ringEnd,
        public float $labelX,
        public float $labelY,
    ) {
    }

    /** @return SectionGeometry */
    public function geometry(): array
    {
        return [
            'angleStart' => round($this->angleStart, 5),
            'angleEnd' => round($this->angleEnd, 5),
            'ringStart' => $this->ringStart,
            'ringEnd' => $this->ringEnd,
            'labelX' => round($this->labelX, 1),
            'labelY' => round($this->labelY, 1),
        ];
    }
}
