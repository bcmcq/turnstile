<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Application\Platform\Dto\ListingRequest;
use App\Application\Platform\Dto\ListingResult;
use App\Domain\Platform\PlatformCode;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What Turnstile needs from any marketplace. Each vendor gets its own implementation because each
 * one has its own auth, resource names, field names and encoding (see the three clients in Http/).
 *
 * Every mutating call takes an idempotency key: a retried call with the same key must not list twice.
 * Implementations throw PlatformApiException for anything the caller should classify.
 */
#[AutoconfigureTag('app.platform_client')]
interface PlatformClientInterface
{
    public function code(): PlatformCode;

    /** @throws PlatformApiException */
    public function list(ListingRequest $request, string $idempotencyKey): ListingResult;

    /** @throws PlatformApiException */
    public function delist(string $externalRef, string $idempotencyKey): void;

    /** @throws PlatformApiException */
    public function reprice(string $externalRef, int $priceCents, string $idempotencyKey): ListingResult;

    /** @return string the new barcode @throws PlatformApiException */
    public function regenerate(string $externalRef, string $idempotencyKey): string;
}
