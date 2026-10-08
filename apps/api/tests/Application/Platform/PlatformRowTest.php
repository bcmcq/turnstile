<?php

declare(strict_types=1);

namespace App\Tests\Application\Platform;

use App\Application\Platform\PlatformRow;
use App\Domain\Platform\PlatformCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlatformRow::class)]
final class PlatformRowTest extends TestCase
{
    public function testListingPriceAppliesTheFeeInBasisPointsAndTheFloor(): void
    {
        $tixhub = new PlatformRow(1, PlatformCode::TixHub, feeBps: 1_000, minPriceCents: 500, rateLimitPerMin: 3_000, webhookSecret: 's', failureRate: 0.0, buyerRate: 0.0);
        $seatswap = new PlatformRow(2, PlatformCode::SeatSwap, feeBps: 1_250, minPriceCents: 500, rateLimitPerMin: 3_000, webhookSecret: 's', failureRate: 0.0, buyerRate: 0.0);

        self::assertSame(7_150, $tixhub->listingPriceFor(6_500));   // +10%
        self::assertSame(7_312, $seatswap->listingPriceFor(6_500)); // +12.5%, truncated cents
        self::assertSame(500, $tixhub->listingPriceFor(100));       // floor
    }
}
