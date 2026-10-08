<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

/** One shared ext-redis connection per process, lazily opened. All keys are prefixed "turnstile:". */
final class RedisFactory
{
    private const string PREFIX = 'turnstile:';
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
        // Workers and the publisher live for an hour or more: retry with backoff instead of dying on one dropped socket.
        $redis->connect($parts['host'], $parts['port'] ?? 6379, 2.0, null, 100);
        $redis->setOption(\Redis::OPT_MAX_RETRIES, 3);
        $redis->setOption(\Redis::OPT_BACKOFF_ALGORITHM, \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER);
        if (isset($parts['pass'])) {
            $redis->auth(isset($parts['user']) ? [$parts['user'], $parts['pass']] : $parts['pass']);
        }
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 2.0); // default is PHP's 60s default_socket_timeout
        $redis->setOption(\Redis::OPT_PREFIX, self::PREFIX);

        return $this->redis = $redis;
    }

    /**
     * SCAN (never KEYS, which blocks the server) for our keys matching the pattern; returned without the prefix.
     *
     * @return list<string>
     */
    public function scanKeys(string $pattern): array
    {
        $redis = $this->get();
        $keys = [];
        $it = null;
        do {
            /** @var array<int, string>|false $batch */
            $batch = $redis->scan($it, self::PREFIX . $pattern, 200);
            foreach (\is_array($batch) ? $batch : [] as $k) {
                $keys[] = substr($k, \strlen(self::PREFIX));
            }
        } while ($it > 0);

        return $keys;
    }
}
