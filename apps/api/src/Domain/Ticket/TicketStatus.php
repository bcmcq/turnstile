<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

enum TicketStatus: string
{
    case Available = 'available';
    case Listed = 'listed';
    case Sold = 'sold';
    case Closed = 'closed';

    /** Compact code sent to the browser in the seats payload. */
    public function code(): int
    {
        return match ($this) {
            self::Available => 0,
            self::Listed => 1,
            self::Sold => 2,
            self::Closed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return self::Sold === $this;
    }
}
