<?php

declare(strict_types=1);

namespace App\Tests\Application\Job;

use App\Application\Job\TicketChange;
use App\Application\Job\TicketRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The version-checked UPDATE against a real MySQL, inside a transaction that is rolled back. */
#[CoversClass(TicketRepository::class)]
final class TicketRepositoryTest extends KernelTestCase
{
    private Connection $db;
    private TicketRepository $tickets;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->tickets = static::getContainer()->get(TicketRepository::class);
        $this->db->beginTransaction();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testAStaleVersionLosesTheRace(): void
    {
        $ticket = $this->tickets->find(1) ?? self::markTestSkipped('arena not seeded');
        $stale = $ticket; // both writers read the same row

        self::assertTrue($this->tickets->apply($ticket, TicketChange::price($ticket->priceCents + 100), null), 'first writer wins');
        self::assertFalse($this->tickets->apply($stale, TicketChange::price($ticket->priceCents + 200), null), 'second writer sees a bumped version and gets nothing');

        $after = $this->tickets->find(1);
        self::assertNotNull($after);
        self::assertSame($ticket->version + 1, $after->version);
        self::assertSame($ticket->priceCents + 100, $after->priceCents, 'the loser did not overwrite the winner');
    }
}
