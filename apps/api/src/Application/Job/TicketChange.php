<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Domain\Ticket\TicketStatus;

/** The new values an action wants on a ticket. Unset fields keep their current value; platform/ref can be set to null explicitly. */
final readonly class TicketChange
{
    /** @param Listing|null $listing null = leave listing fields alone */
    private function __construct(
        public ?TicketStatus $status,
        public ?Listing $listing,
        public ?int $priceCents,
        public ?string $barcode,
    ) {
    }

    public static function listed(int $platformId, string $externalRef, int $priceCents): self
    {
        return new self(TicketStatus::Listed, new Listing($platformId, $externalRef), $priceCents, null);
    }

    public static function unlisted(TicketStatus $status, int $priceCents): self
    {
        return new self($status, new Listing(null, null), $priceCents, null);
    }

    public static function price(int $priceCents): self
    {
        return new self(null, null, $priceCents, null);
    }

    public static function barcode(string $barcode): self
    {
        return new self(null, null, null, $barcode);
    }

    public static function status(TicketStatus $status): self
    {
        return new self($status, null, null, null);
    }

    public function isNoop(): bool
    {
        return null === $this->status && null === $this->listing && null === $this->priceCents && null === $this->barcode;
    }
}
