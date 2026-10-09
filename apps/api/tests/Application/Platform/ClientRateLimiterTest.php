<?php

declare(strict_types=1);

namespace App\Tests\Application\Platform;

use App\Application\Platform\ClientRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Symfony's bucket refills in whole intervals, so the interval must stay short while the rate stays exact. */
#[CoversClass(ClientRateLimiter::class)]
final class ClientRateLimiterTest extends TestCase
{
    /** @return iterable<string, array{int, int, int}> */
    public static function rates(): iterable
    {
        yield 'default 3000 rpm' => [2400, 1, 40];
        yield '100 rpm, the slider step' => [80, 3, 4];
        yield '60 rpm, the minimum' => [48, 5, 4];
        yield 'coprime with 60 is capped and rounded' => [7, 5, 1];
    }

    #[DataProvider('rates')]
    public function testRefillKeepsTheIntervalShortAndTheRateExact(int $perMin, int $seconds, int $amount): void
    {
        self::assertSame([$seconds, $amount], ClientRateLimiter::refill($perMin));
        self::assertLessThanOrEqual(5, $seconds);
    }
}
