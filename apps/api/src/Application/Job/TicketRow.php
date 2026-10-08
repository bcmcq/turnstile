<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Domain\Ticket\TicketStatus;

final readonly class TicketRow
{
    public function __construct(
        public int $id,
        public int $eventId,
        public int $sectionId,
        public TicketStatus $status,
        public ?int $platformId,
        public ?string $externalRef,
        public int $faceValueCents,
        public int $priceCents,
        public string $barcode,
        public int $version,
    ) {
    }
}
