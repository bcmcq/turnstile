<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Platform\PlatformCode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Client-side pacing, one shared token bucket per platform (Redis-backed, see config/packages/rate_limiter.yaml).
 * acquire() blocks the worker until a token is available; that wait is what keeps us under the vendor's limit.
 */
final class ClientRateLimiter
{
    /** Must match config/packages/rate_limiter.yaml. */
    public const int CAPACITY = 200;
    public const int TOKENS_PER_SECOND = 40;
    private const string BUCKET = 'shared';

    public function __construct(
        #[Autowire(service: 'limiter.platform_tixhub')]
        private readonly RateLimiterFactoryInterface $tixhub,
        #[Autowire(service: 'limiter.platform_seatswap')]
        private readonly RateLimiterFactoryInterface $seatswap,
        #[Autowire(service: 'limiter.platform_passmarket')]
        private readonly RateLimiterFactoryInterface $passmarket,
    ) {
    }

    /** @return int milliseconds waited */
    public function acquire(PlatformCode $code): int
    {
        $start = hrtime(true);
        $this->factory($code)->create(self::BUCKET)->reserve()->wait();

        return (int) ((hrtime(true) - $start) / 1e6);
    }

    public function remaining(PlatformCode $code): int
    {
        return $this->factory($code)->create(self::BUCKET)->consume(0)->getRemainingTokens();
    }

    private function factory(PlatformCode $code): RateLimiterFactoryInterface
    {
        return match ($code) {
            PlatformCode::TixHub => $this->tixhub,
            PlatformCode::SeatSwap => $this->seatswap,
            PlatformCode::PassMarket => $this->passmarket,
        };
    }
}
