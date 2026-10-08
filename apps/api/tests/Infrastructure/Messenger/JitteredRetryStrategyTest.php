<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messenger;

use App\Application\Job\Exception\LockConflictException;
use App\Application\Job\Exception\PlatformRejectedException;
use App\Application\Job\Exception\PlatformRetryableException;
use App\Application\Platform\PlatformApiException;
use App\Application\Platform\PlatformFailure;
use App\Infrastructure\Messenger\JitteredRetryStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

#[CoversClass(JitteredRetryStrategy::class)]
final class JitteredRetryStrategyTest extends TestCase
{
    private JitteredRetryStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new JitteredRetryStrategy();
    }

    public function testFiveAttemptsInTotal(): void
    {
        self::assertTrue($this->strategy->isRetryable($this->envelope(0)));
        self::assertTrue($this->strategy->isRetryable($this->envelope(3)));
        self::assertFalse($this->strategy->isRetryable($this->envelope(4)));
    }

    public function testBackoffDoublesWithBoundedJitterAndCapsAtEightSeconds(): void
    {
        foreach ([0 => 1_000, 1 => 2_000, 2 => 4_000, 3 => 8_000, 4 => 8_000] as $retries => $base) {
            for ($i = 0; $i < 20; ++$i) {
                $wait = $this->strategy->getWaitingTime($this->envelope($retries));
                self::assertGreaterThanOrEqual($base * 0.75, $wait, "retry {$retries}");
                self::assertLessThanOrEqual($base * 1.25, $wait, "retry {$retries}");
            }
        }
    }

    public function testRetryAfterFromThePlatformWinsOverTheSchedule(): void
    {
        $cause = new PlatformApiException(PlatformFailure::RateLimited, '429', 429, retryAfterMs: 4_321);
        $wrapped = new HandlerFailedException($this->envelope(0), [new PlatformRetryableException($cause)]);

        self::assertSame(4_321, $this->strategy->getWaitingTime($this->envelope(0), $wrapped));
    }

    public function testLockConflictRetriesQuicklyWithJitter(): void
    {
        $wrapped = new HandlerFailedException($this->envelope(0), [new LockConflictException(42, 5)]);
        $wait = $this->strategy->getWaitingTime($this->envelope(0), $wrapped);

        self::assertGreaterThanOrEqual(200, $wait);
        self::assertLessThanOrEqual(600, $wait);
    }

    public function testRejectedByThePlatformIsNeverRetried(): void
    {
        $cause = new PlatformApiException(PlatformFailure::Rejected, '400', 400);
        $wrapped = new HandlerFailedException($this->envelope(0), [new PlatformRejectedException($cause)]);

        self::assertFalse($this->strategy->isRetryable($this->envelope(0), $wrapped));
    }

    private function envelope(int $retries): Envelope
    {
        $envelope = new Envelope(new \stdClass());

        return $retries > 0 ? $envelope->with(new RedeliveryStamp($retries)) : $envelope;
    }
}
