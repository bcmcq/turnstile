<?php

declare(strict_types=1);

namespace App\Application\Realtime\Dto;

final readonly class PlatformGauge
{
    public function __construct(
        public string $code,
        public string $name,
        public string $color,
        public int $remainingTokens,
        public int $capacity,
        public int $tokensPerSec,
        public int $rateLimitPerMin,
        public int $calls,
        public int $ok,
        public int $http429,
        public int $http5xx,
        public int $timeouts,
        public int $rejected,
        public float $failureRate,
        public float $buyerRate,
        public int $listed,
    ) {
    }
}
