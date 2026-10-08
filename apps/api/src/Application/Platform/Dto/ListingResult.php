<?php

declare(strict_types=1);

namespace App\Application\Platform\Dto;

final readonly class ListingResult
{
    public function __construct(
        public string $externalRef,
        public int $priceCents,
        public int $latencyMs,
    ) {
    }
}
