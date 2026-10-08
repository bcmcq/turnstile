<?php

declare(strict_types=1);

namespace App\Application\Arena\Dto;

use App\Domain\Platform\PlatformCode;

final readonly class PlatformView
{
    public function __construct(
        public int $id,
        public PlatformCode $code,
        public string $name,
        public string $color,
        public int $rateLimitPerMin,
        public float $clientPaceRatio,
        public int $feeBps,
        public float $failureRate,
        public float $buyerRate,
    ) {
    }
}
