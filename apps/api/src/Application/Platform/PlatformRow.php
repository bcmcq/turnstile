<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Platform\PlatformCode;

final readonly class PlatformRow
{
    public function __construct(
        public int $id,
        public PlatformCode $code,
        public int $feeBps,
        public int $minPriceCents,
        public int $rateLimitPerMin,
        public string $webhookSecret,
        public float $failureRate,
        public float $buyerRate,
    ) {
    }

    public function listingPriceFor(int $faceValueCents): int
    {
        return max($this->minPriceCents, intdiv($faceValueCents * (10_000 + $this->feeBps), 10_000));
    }
}
