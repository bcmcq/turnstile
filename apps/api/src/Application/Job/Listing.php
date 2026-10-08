<?php

declare(strict_types=1);

namespace App\Application\Job;

/** Where a ticket is listed. Both null = house inventory. */
final readonly class Listing
{
    public function __construct(
        public ?int $platformId,
        public ?string $externalRef,
    ) {
    }
}
