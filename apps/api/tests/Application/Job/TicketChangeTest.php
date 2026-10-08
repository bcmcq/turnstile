<?php

declare(strict_types=1);

namespace App\Tests\Application\Job;

use App\Application\Job\TicketChange;
use App\Domain\Ticket\TicketStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TicketChange::class)]
final class TicketChangeTest extends TestCase
{
    public function testListedSetsStatusListingAndPrice(): void
    {
        $c = TicketChange::listed(2, 'SS-1', 7_312);

        self::assertSame(TicketStatus::Listed, $c->status);
        self::assertSame(['platformId' => 2, 'externalRef' => 'SS-1'], $c->listing);
        self::assertSame(7_312, $c->priceCents);
        self::assertNull($c->barcode);
        self::assertFalse($c->isNoop());
    }

    public function testUnlistedClearsTheListingExplicitly(): void
    {
        $c = TicketChange::unlisted(TicketStatus::Closed, 6_500);

        self::assertSame(['platformId' => null, 'externalRef' => null], $c->listing, 'null platform must be written, not skipped');
        self::assertSame(TicketStatus::Closed, $c->status);
    }

    public function testPriceOnlyLeavesListingAlone(): void
    {
        $c = TicketChange::price(9_000);

        self::assertNull($c->listing);
        self::assertNull($c->status);
        self::assertSame(9_000, $c->priceCents);
    }
}
