<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Platform\PlatformCode;
use App\Infrastructure\Redis\RedisFactory;
use Doctrine\DBAL\Connection;

/** Platforms change rarely but the publisher is long-lived, so rows are cached for a few seconds. Chaos/buyer rates are mirrored to Redis for the mocks. */
final class PlatformRepository
{
    private const float CACHE_SECONDS = 2.0;

    /** @var array<int, PlatformRow>|null */
    private ?array $rows = null;
    private float $loadedAt = 0.0;

    public function __construct(private readonly Connection $db, private readonly RedisFactory $redis)
    {
    }

    /** @return list<PlatformRow> */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function byId(int $id): PlatformRow
    {
        return $this->load()[$id] ?? throw new \OutOfBoundsException("Unknown platform id {$id}");
    }

    public function byCode(PlatformCode $code): PlatformRow
    {
        foreach ($this->load() as $row) {
            if ($row->code === $code) {
                return $row;
            }
        }
        throw new \OutOfBoundsException("Unknown platform {$code->value}");
    }

    public function setFailureRate(PlatformCode $code, float $rate): void
    {
        $rate = max(0.0, min(1.0, $rate));
        $this->db->executeStatement('UPDATE platforms SET failure_rate = ?, updated_at = NOW() WHERE code = ?', [number_format($rate, 3, '.', ''), $code->value]);
        $this->redis->get()->set("chaos:{$code->value}", (string) $rate);
        $this->rows = null;
    }

    public function setBuyerRate(PlatformCode $code, float $rate): void
    {
        $rate = max(0.0, min(1.0, $rate));
        $this->db->executeStatement('UPDATE platforms SET buyer_rate = ?, updated_at = NOW() WHERE code = ?', [number_format($rate, 3, '.', ''), $code->value]);
        $this->redis->get()->set("buyers:{$code->value}", (string) $rate);
        $this->rows = null;
    }

    /** Push DB values to Redis (on boot, so the mocks see the seeded defaults). */
    /** Vendor limit slider: the mocks enforce it from Redis within a request; workers re-read rows every few seconds. */
    public function setRateLimit(PlatformCode $code, int $rpm): void
    {
        $this->db->executeStatement('UPDATE platforms SET rate_limit_per_min = ?, updated_at = NOW() WHERE code = ?', [$rpm, $code->value]);
        $this->redis->get()->set("ratelimit:{$code->value}:limit", (string) $rpm);
        $this->rows = null;
    }

    public function syncToRedis(): void
    {
        $redis = $this->redis->get();
        foreach ($this->all() as $row) {
            $redis->set("chaos:{$row->code->value}", (string) $row->failureRate);
            $redis->set("buyers:{$row->code->value}", (string) $row->buyerRate);
            $redis->set("ratelimit:{$row->code->value}:limit", (string) $row->rateLimitPerMin);
        }
    }

    /** @return array<int, PlatformRow> */
    private function load(): array
    {
        if (null !== $this->rows && microtime(true) - $this->loadedAt < self::CACHE_SECONDS) {
            return $this->rows;
        }
        $rows = [];
        /** @var array{id: int, code: string, fee_bps: int, min_price_cents: int, rate_limit_per_min: int, webhook_secret: string, failure_rate: string, buyer_rate: string} $r */
        foreach ($this->db->iterateAssociative('SELECT id, code, fee_bps, min_price_cents, rate_limit_per_min, webhook_secret, failure_rate, buyer_rate FROM platforms ORDER BY id') as $r) {
            $rows[(int) $r['id']] = new PlatformRow((int) $r['id'], PlatformCode::from($r['code']), (int) $r['fee_bps'], (int) $r['min_price_cents'], (int) $r['rate_limit_per_min'], $r['webhook_secret'], (float) $r['failure_rate'], (float) $r['buyer_rate']);
        }

        $this->loadedAt = microtime(true);

        return $this->rows = $rows;
    }
}
