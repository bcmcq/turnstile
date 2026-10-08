<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Platform\PlatformCode;
use App\Infrastructure\Redis\RedisFactory;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Client-side pacing: one shared token bucket per platform in Redis, sized from the vendor's limit at
 * PACE_RATIO. acquire() blocks the worker until a token is available; that wait keeps us under the limit.
 *
 * The bucket follows the vendor limit slider (bucket id includes the rpm, so a change starts a fresh bucket)
 * unless "pace to limit" is off, in which case it stays at DEFAULT_RPM and the vendor's 429s do the pacing.
 */
final class ClientRateLimiter
{
    public const int DEFAULT_RPM = 3_000;
    public const float PACE_RATIO = 0.8;
    private const int BURST_SECONDS = 5;
    private const string BUCKET = 'shared';
    private const string FOLLOW_KEY = 'pacing:follow';
    private const float FLAG_TTL = 2.0;

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];
    private ?bool $follow = null;
    private float $followReadAt = 0.0;

    public function __construct(
        private readonly PlatformRepository $platforms,
        private readonly RedisFactory $redis,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $cache,
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

    public function tokensPerSec(PlatformCode $code): int
    {
        return max(1, (int) round($this->paceRpm($code) / 60 * self::PACE_RATIO));
    }

    public function capacity(PlatformCode $code): int
    {
        return $this->tokensPerSec($code) * self::BURST_SECONDS;
    }

    /** The rpm the client paces against: the vendor's current limit, or the default when not following. */
    public function paceRpm(PlatformCode $code): int
    {
        return $this->followsVendorLimit() ? $this->platforms->byCode($code)->rateLimitPerMin : self::DEFAULT_RPM;
    }

    public function followsVendorLimit(): bool
    {
        $now = microtime(true);
        if (null === $this->follow || $now - $this->followReadAt > self::FLAG_TTL) {
            $this->follow = '0' !== $this->redis->get()->get(self::FOLLOW_KEY);
            $this->followReadAt = $now;
        }

        return $this->follow;
    }

    public function setFollowsVendorLimit(bool $follow): void
    {
        $this->redis->get()->set(self::FOLLOW_KEY, $follow ? '1' : '0');
        $this->follow = $follow;
        $this->followReadAt = microtime(true);
    }

    private function factory(PlatformCode $code): RateLimiterFactory
    {
        $perSec = $this->tokensPerSec($code);
        $id = \sprintf('platform_%s_%d', $code->value, $perSec);

        return $this->factories[$id] ??= new RateLimiterFactory(
            ['id' => $id, 'policy' => 'token_bucket', 'limit' => $perSec * self::BURST_SECONDS, 'rate' => ['interval' => '1 second', 'amount' => $perSec]],
            new CacheStorage($this->cache),
        );
    }
}
