<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Domain\Platform\PlatformCode;
use App\Infrastructure\Redis\RedisFactory;

/** Per-platform call outcome counters (monotonic, Redis hash). */
final class PlatformStats
{
    public const array FIELDS = ['calls', 'ok', 'http_429', 'http_5xx', 'timeouts', 'rejected'];

    public function __construct(private readonly RedisFactory $redis)
    {
    }

    public function incr(PlatformCode $code, string $field): void
    {
        $this->redis->get()->hIncrBy("platform:{$code->value}:stats", $field, 1);
    }

    /** @return array<string, int> */
    public function all(PlatformCode $code): array
    {
        /** @var array<string, string>|false $h */
        $h = $this->redis->get()->hGetAll("platform:{$code->value}:stats");
        $out = array_fill_keys(self::FIELDS, 0);
        foreach (\is_array($h) ? $h : [] as $k => $v) {
            $out[$k] = (int) $v;
        }

        return $out;
    }
}
