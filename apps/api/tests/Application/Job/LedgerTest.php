<?php

declare(strict_types=1);

namespace App\Tests\Application\Job;

use App\Application\Job\Ledger;
use App\Application\Job\TicketChange;
use App\Application\Job\TicketRepository;
use App\Domain\Job\ActionState;
use App\Domain\Run\RunType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The unique index on (ticket_id, run_id) against a real MySQL, inside a transaction that is rolled back. */
#[CoversClass(Ledger::class)]
final class LedgerTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    public function testRetryReattachesToThePendingRowAndReplayFindsItApplied(): void
    {
        $ticket = static::getContainer()->get(TicketRepository::class)->find(1) ?? self::markTestSkipped('arena not seeded');
        $runId = Uuid::v7()->toRfc4122();
        $run = Uuid::fromString($runId)->toBinary();
        $this->db->executeStatement('INSERT INTO runs (id, number, type, status, event_id, selection, params, total_jobs, completed_jobs, skipped_jobs, dead_lettered_jobs, created_at, updated_at) VALUES (?, 999999, ?, ?, ?, ?, ?, 0, 0, 0, 0, NOW(), NOW())', [$run, RunType::Reprice->value, 'running', $ticket->eventId, '{}', '{}']);
        $this->db->executeStatement('INSERT INTO jobs (id, run_id, ticket_id, status, attempts, max_attempts, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 5, NOW(), NOW())', [999_999_001, $run, $ticket->id, 'queued']);
        $this->db->executeStatement('INSERT INTO jobs (id, run_id, ticket_id, status, attempts, max_attempts, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 5, NOW(), NOW())', [999_999_002, $run, $ticket->id, 'queued']);
        $ledger = new Ledger($this->db);
        $key = Ledger::idempotencyKey($runId, $ticket->id, RunType::Reprice);

        $first = $ledger->claim($ticket->id, $runId, 999_999_001, RunType::Reprice, $key);
        $retry = $ledger->claim($ticket->id, $runId, 999_999_002, RunType::Reprice, $key);
        self::assertSame(ActionState::Pending, $first->state);
        self::assertSame($first->id, $retry->id, 'a retried job carries on with the same claim');
        self::assertSame(999_999_002, $this->int('SELECT job_id FROM ticket_actions WHERE id = ?', $first->id));

        $ledger->apply($first->id, $ticket, TicketChange::price($ticket->priceCents + 100));
        $replay = $ledger->claim($ticket->id, $runId, 999_999_001, RunType::Reprice, $key);
        self::assertSame(ActionState::Applied, $replay->state, 'a replay must stop at the guard');
        self::assertSame($ticket->priceCents + 100, $this->int('SELECT to_price_cents FROM ticket_actions WHERE id = ?', $first->id));

        $ledger->release($first->id);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM ticket_actions WHERE id = ?', $first->id), 'release only drops pending rows');
    }

    private function int(string $sql, int $param): int
    {
        $v = $this->db->fetchOne($sql, [$param]);
        self::assertTrue(is_numeric($v));

        return (int) $v;
    }
}
