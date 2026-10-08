<?php

declare(strict_types=1);

namespace App\Application\Platform\Http;

use App\Application\Platform\Dto\ListingRequest;
use App\Application\Platform\Dto\ListingResult;
use App\Domain\Platform\PlatformCode;

/** TixHub: plain REST, JSON bodies, bearer token, "Idempotency-Key" header. */
final class TixHubClient extends AbstractHttpPlatformClient
{
    private const string IDEMPOTENCY_HEADER = 'Idempotency-Key';

    #[\Override]
    public function code(): PlatformCode
    {
        return PlatformCode::TixHub;
    }

    #[\Override]
    public function list(ListingRequest $request, string $idempotencyKey): ListingResult
    {
        [$body, $ms] = $this->call('POST', '/listings', ['json' => ['ticket_id' => $request->ticketId, 'price_cents' => $request->priceCents, 'barcode' => $request->barcode]], $idempotencyKey, self::IDEMPOTENCY_HEADER);

        return new ListingResult($this->str($body, 'id'), $this->int($body, 'price_cents'), $ms);
    }

    #[\Override]
    public function delist(string $externalRef, string $idempotencyKey): void
    {
        $this->call('DELETE', '/listings/' . $externalRef, [], $idempotencyKey, self::IDEMPOTENCY_HEADER);
    }

    #[\Override]
    public function reprice(string $externalRef, int $priceCents, string $idempotencyKey): ListingResult
    {
        [$body, $ms] = $this->call('PATCH', '/listings/' . $externalRef . '/price', ['json' => ['price_cents' => $priceCents]], $idempotencyKey, self::IDEMPOTENCY_HEADER);

        return new ListingResult($externalRef, $this->int($body, 'price_cents'), $ms);
    }

    #[\Override]
    public function regenerate(string $externalRef, string $idempotencyKey): string
    {
        [$body] = $this->call('POST', '/listings/' . $externalRef . '/regenerate', [], $idempotencyKey, self::IDEMPOTENCY_HEADER);

        return $this->str($body, 'barcode');
    }

    #[\Override]
    protected function defaultHeaders(): array
    {
        return ['Authorization' => 'Bearer tixhub-demo-token'];
    }
}
