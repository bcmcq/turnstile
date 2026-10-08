<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

/** One shared ext-redis connection per process, lazily opened. All keys are prefixed "turnstile:". */
final class RedisFactory
{
    private ?\Redis $redis = null;

    public function __construct(private readonly string $redisUrl)
    {
    }

    public function get(): \Redis
    {
        if (null !== $this->redis) {
            return $this->redis;
        }
        $parts = parse_url($this->redisUrl);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException(\sprintf('Invalid REDIS_URL "%s"', $this->redisUrl));
        }
        $redis = new \Redis();
        $redis->connect($parts['host'], $parts['port'] ?? 6379, 2.0);
        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0); // default is PHP's 60s default_socket_timeout
        $redis->setOption(\Redis::OPT_PREFIX, 'turnstile:');

        return $this->redis = $redis;
    }
}
