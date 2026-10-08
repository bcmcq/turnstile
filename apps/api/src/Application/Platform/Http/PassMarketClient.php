<?php

declare(strict_types=1);

namespace App\Application\Platform\Http;

use App\Application\Platform\Dto\ListingRequest;
use App\Application\Platform\Dto\ListingResult;
use App\Domain\Platform\PlatformCode;

/** PassMarket: "offers" under /v2, form-encoded requests, basic auth, every mutation is a POST. */
final class PassMarketClient extends AbstractHttpPlatformClient
{
    private const string IDEMPOTENCY_HEADER = 'Idempotency-Key';

    #[\Override]
    public function code(): PlatformCode
    {
        return PlatformCode::PassMarket;
    }

    #[\Override]
    public function list(ListingRequest $request, string $idempotencyKey): ListingResult
    {
        [$body, $ms] = $this->call('POST', '/v2/offers', ['body' => ['ticket' => $request->ticketId, 'price_cents' => $request->priceCents, 'barcode' => $request->barcode]], $idempotencyKey, self::IDEMPOTENCY_HEADER);
        $offer = self::offer($body);

        return new ListingResult($this->str($offer, 'id'), $this->int($offer, 'price_cents'), $ms);
    }

    #[\Override]
    public function delist(string $externalRef, string $idempotencyKey): void
    {
        $this->call('POST', '/v2/offers/' . $externalRef . '/withdraw', ['body' => []], $idempotencyKey, self::IDEMPOTENCY_HEADER);
    }

    #[\Override]
    public function reprice(string $externalRef, int $priceCents, string $idempotencyKey): ListingResult
    {
        [$body, $ms] = $this->call('POST', '/v2/offers/' . $externalRef . '/price', ['body' => ['price_cents' => $priceCents]], $idempotencyKey, self::IDEMPOTENCY_HEADER);

        return new ListingResult($externalRef, $this->int(self::offer($body), 'price_cents'), $ms);
    }

    #[\Override]
    public function regenerate(string $externalRef, string $idempotencyKey): string
    {
        [$body] = $this->call('POST', '/v2/offers/' . $externalRef . '/barcode', ['body' => []], $idempotencyKey, self::IDEMPOTENCY_HEADER);

        return $this->str(self::offer($body), 'barcode');
    }

    #[\Override]
    protected function defaultHeaders(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode('turnstile:passmarket-demo')];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function offer(array $body): array
    {
        $offer = $body['offer'] ?? null;
        if (!\is_array($offer)) {
            return [];
        }
        /** @var array<string, mixed> $offer */

        return $offer;
    }
}
