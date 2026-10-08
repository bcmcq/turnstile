<?php

declare(strict_types=1);

namespace App\Application\Platform\Dto;

final readonly class ListingRequest
{
    public function __construct(
        public int $ticketId,
        public int $priceCents,
        public string $barcode,
    ) {
    }
}
