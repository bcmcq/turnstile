<?php

declare(strict_types=1);

namespace App\Application\Platform\Http;

use App\Application\Platform\Dto\ListingRequest;
use App\Application\Platform\Dto\ListingResult;
use App\Domain\Platform\PlatformCode;

/** SeatSwap: "inventory" vocabulary, prices as decimal dollar strings, API key header, "X-Idempotency-Key". */
final class SeatSwapClient extends AbstractHttpPlatformClient
{
    private const string IDEMPOTENCY_HEADER = 'X-Idempotency-Key';

    #[\Override]
    public function code(): PlatformCode
    {
        return PlatformCode::SeatSwap;
    }

    #[\Override]
    public function list(ListingRequest $request, string $idempotencyKey): ListingResult
    {
        $r = $this->call('POST', '/inventory', ['json' => ['externalId' => $request->ticketId, 'askingPrice' => self::dollars($request->priceCents), 'barcode' => $request->barcode]], $idempotencyKey, self::IDEMPOTENCY_HEADER);

        return new ListingResult($r->str('inventoryId'), self::cents($r->str('askingPrice')), $r->latencyMs);
    }

    #[\Override]
    public function delist(string $externalRef, string $idempotencyKey): void
    {
        $this->call('DELETE', '/inventory/' . $externalRef, [], $idempotencyKey, self::IDEMPOTENCY_HEADER);
    }

    #[\Override]
    public function reprice(string $externalRef, int $priceCents, string $idempotencyKey): ListingResult
    {
        $r = $this->call('PUT', '/inventory/' . $externalRef, ['json' => ['askingPrice' => self::dollars($priceCents)]], $idempotencyKey, self::IDEMPOTENCY_HEADER);

        return new ListingResult($externalRef, self::cents($r->str('askingPrice')), $r->latencyMs);
    }

    #[\Override]
    public function regenerate(string $externalRef, string $idempotencyKey): string
    {
        return $this->call('POST', '/inventory/' . $externalRef . '/reissue', [], $idempotencyKey, self::IDEMPOTENCY_HEADER)->str('barcode');
    }

    #[\Override]
    protected function defaultHeaders(): array
    {
        return ['X-Api-Key' => 'seatswap-demo-key'];
    }

    private static function dollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function cents(string $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }
}
