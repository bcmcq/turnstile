<?php

declare(strict_types=1);

namespace Mocks;

/**
 * One fake marketplace. Three instances (tixhub, seatswap, passmarket) share this behavior but expose
 * different API shapes (see Router), the way three real vendors would.
 *
 * Request pipeline: auth → rate limit → idempotency replay → latency → chaos → execute → buyer snipe → cache.
 */
final class Marketplace
{
    private const string SHARED = 'turnstile:'; // keys the API writes (chaos, buyers, rate limit)
    private const string OWN = 'mock:';

    /** @param array{min: int, max: int} $latencyMs */
    public function __construct(
        public readonly string $code,
        private readonly \Redis $redis,
        private readonly string $apiWebhookUrl,
        private readonly array $latencyMs,
        private readonly int $defaultRateLimit = 3_000,
    ) {}

    /** @return array{int, array<string, mixed>, array<string, string>} status, body, headers */
    public function handle(string $op, string $idempotencyKey, callable $execute): array
    {
        $this->bump('requests');

        // 1. Rate limit: fixed window per minute, enforced at 100% (the client paces itself at 80%).
        $window = intdiv(time(), 60);
        $key = self::OWN."rl:{$this->code}:{$window}";
        $count = (int) $this->redis->incr($key);
        if (1 === $count) {
            $this->redis->expire($key, 65);
        }
        $limit = (int) ($this->redis->get(self::SHARED."ratelimit:{$this->code}:limit") ?: $this->defaultRateLimit);
        if ($count > $limit) {
            $this->bump('429');
            $retryAfter = 60 - (time() % 60);

            return [429, ['error' => 'rate_limited', 'retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter, 'X-RateLimit-Remaining' => '0']];
        }

        // 2. Idempotency replay: same key within 24h returns the cached response without side effects.
        $idemKey = self::OWN."idem:{$this->code}:{$idempotencyKey}";
        if ('' !== $idempotencyKey) {
            $cached = $this->redis->get($idemKey);
            if (\is_string($cached)) {
                $this->bump('idempotent_replays');
                /** @var array{status: int, body: array<string, mixed>} $decoded */
                $decoded = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);

                return [$decoded['status'], $decoded['body'], ['Idempotent-Replayed' => 'true']];
            }
        }

        // 3. Latency, with a 2% chance of a stall that outlives the client's 3s timeout.
        if (random_int(1, 100) <= 2) {
            $this->bump('stalls');
            usleep(3_500_000);
        } else {
            usleep(random_int($this->latencyMs['min'], $this->latencyMs['max']) * 1000);
        }

        // 4. Chaos.
        $failureRate = (float) ($this->redis->get(self::SHARED."chaos:{$this->code}") ?: '0');
        if ($failureRate > 0 && random_int(1, 1000) <= (int) round($failureRate * 1000)) {
            $this->bump('5xx');
            $roll = random_int(1, 10);
            if (10 === $roll) {
                // "Dropped" upstream: no JSON body at all, the way a dead proxy answers.
                http_response_code(502);
                header('Connection: close');
                exit;
            }

            return [$roll <= 7 ? 500 : 503, ['error' => 'upstream_error', 'chaos' => true], []];
        }

        // 5. Execute.
        [$status, $body, $listingId] = $execute();
        $this->bump('ok');

        // 6. Buyer snipe on create/reprice: fire the webhook first, then answer the worker late.
        if (null !== $listingId && \in_array($op, ['create', 'reprice'], true)) {
            $buyerRate = (float) ($this->redis->get(self::SHARED."buyers:{$this->code}") ?: '0');
            if ($buyerRate > 0 && random_int(1, 1000) <= (int) round($buyerRate * 1000)) {
                $this->sell($listingId);
                usleep(random_int(50, 150) * 1000);
            }
        }

        if ('' !== $idempotencyKey) {
            $this->redis->setex($idemKey, 86_400, json_encode(['status' => $status, 'body' => $body], JSON_THROW_ON_ERROR));
        }

        return [$status, $body, []];
    }

    /** @return array{int, array<string, mixed>, string} */
    public function createListing(int $ticketId, int $priceCents, string $barcode): array
    {
        $id = strtoupper(substr($this->code, 0, 2)).'-'.strtoupper(bin2hex(random_bytes(4)));
        $this->redis->hMSet(self::OWN."listing:{$this->code}:{$id}", ['id' => $id, 'ticket_id' => $ticketId, 'price_cents' => $priceCents, 'barcode' => $barcode, 'status' => 'active', 'created_at' => microtime(true)]);
        $this->redis->incr(self::OWN."count:{$this->code}:active");

        return [201, ['id' => $id, 'ticket_id' => $ticketId, 'price_cents' => $priceCents, 'status' => 'active'], $id];
    }

    /** @return array{int, array<string, mixed>, string|null} */
    public function delist(string $id): array
    {
        $listing = $this->listing($id);
        if (null === $listing) {
            return [404, ['error' => 'not_found'], null];
        }
        if ('active' === $listing['status']) {
            $this->redis->hSet(self::OWN."listing:{$this->code}:{$id}", 'status', 'delisted');
            $this->redis->decr(self::OWN."count:{$this->code}:active");
        }

        return [200, ['id' => $id, 'status' => 'delisted'], null];
    }

    /** @return array{int, array<string, mixed>, string|null} */
    public function reprice(string $id, int $priceCents): array
    {
        $listing = $this->listing($id);
        if (null === $listing) {
            return [404, ['error' => 'not_found'], null];
        }
        if ('sold' === $listing['status']) {
            return [409, ['error' => 'listing_sold'], null];
        }
        $this->redis->hSet(self::OWN."listing:{$this->code}:{$id}", 'price_cents', $priceCents);

        return [200, ['id' => $id, 'price_cents' => $priceCents, 'status' => $listing['status']], $id];
    }

    /** @return array{int, array<string, mixed>, string|null} */
    public function regenerate(string $id): array
    {
        $listing = $this->listing($id);
        if (null === $listing) {
            return [404, ['error' => 'not_found'], null];
        }
        if ('sold' === $listing['status']) {
            return [409, ['error' => 'listing_sold'], null];
        }
        $barcode = strtoupper(substr($this->code, 0, 2)).'-'.strtoupper(bin2hex(random_bytes(2))).'-'.str_pad((string) $listing['ticket_id'], 6, '0', STR_PAD_LEFT);
        $this->redis->hSet(self::OWN."listing:{$this->code}:{$id}", 'barcode', $barcode);

        return [200, ['id' => $id, 'barcode' => $barcode], null];
    }

    /** @return array{id: string, ticket_id: int, price_cents: int, barcode: string, status: string}|null */
    public function listing(string $id): ?array
    {
        /** @var array<string, string>|false $h */
        $h = $this->redis->hGetAll(self::OWN."listing:{$this->code}:{$id}");
        if (!\is_array($h) || [] === $h) {
            return null;
        }

        return ['id' => $h['id'], 'ticket_id' => (int) $h['ticket_id'], 'price_cents' => (int) $h['price_cents'], 'barcode' => $h['barcode'], 'status' => $h['status']];
    }

    /** @return array<string, int|float|string> */
    public function stats(): array
    {
        /** @var array<string, string> $h */
        $h = $this->redis->hGetAll(self::OWN."stats:{$this->code}") ?: [];
        $out = array_map(intval(...), $h);
        $out['active_listings'] = (int) $this->redis->get(self::OWN."count:{$this->code}:active");
        $out['failure_rate'] = (float) ($this->redis->get(self::SHARED."chaos:{$this->code}") ?: '0');
        $out['buyer_rate'] = (float) ($this->redis->get(self::SHARED."buyers:{$this->code}") ?: '0');
        $out['rate_limit_per_min'] = (int) ($this->redis->get(self::SHARED."ratelimit:{$this->code}:limit") ?: $this->defaultRateLimit);

        return $out;
    }

    public function setShared(string $name, string $value): void
    {
        $this->redis->set(self::SHARED."{$name}:{$this->code}".('ratelimit' === $name ? ':limit' : ''), $value);
    }

    public static function webhookSecret(string $code): string
    {
        return hash('sha256', 'turnstile-webhook-'.$code); // same derivation as the API seeder
    }

    /** A buyer bought the listing: mark it sold and tell the API by signed webhook. */
    private function sell(string $id): void
    {
        $listing = $this->listing($id);
        if (null === $listing || 'sold' === $listing['status']) {
            return;
        }
        $this->redis->hSet(self::OWN."listing:{$this->code}:{$id}", 'status', 'sold');
        $this->redis->decr(self::OWN."count:{$this->code}:active");
        $this->bump('sold');

        $body = json_encode([
            'event' => 'listing.sold',
            'delivery_id' => self::uuid(),
            'external_ref' => $id,
            'ticket_id' => $listing['ticket_id'],
            'sold_price_cents' => $listing['price_cents'],
            'occurred_at' => (new \DateTimeImmutable())->format(DATE_RFC3339_EXTENDED),
        ], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, self::webhookSecret($this->code));

        $ch = curl_init($this->apiWebhookUrl.'/'.$this->code);
        if (false === $ch) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Signature: '.$signature],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 1_000,
        ]);
        curl_exec($ch);
        $this->bump(200 === curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ? 'webhooks_sent' : 'webhooks_failed');
    }

    private function bump(string $field): void
    {
        $this->redis->hIncrBy(self::OWN."stats:{$this->code}", $field, 1);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
