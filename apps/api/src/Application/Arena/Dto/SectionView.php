<?php

declare(strict_types=1);

namespace App\Application\Arena\Dto;

use App\Domain\Venue\SectionStatus;
use App\Domain\Venue\SectionTier;

/**
 * @phpstan-import-type SectionGeometry from \App\Domain\Venue\Section
 */
final readonly class SectionView
{
    /** @param SectionGeometry $geometry */
    public function __construct(
        public int $id,
        public string $code,
        public SectionTier $tier,
        public SectionStatus $status,
        public int $seatCount,
        public array $geometry,
    ) {
    }
}
