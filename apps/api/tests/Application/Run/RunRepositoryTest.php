<?php

declare(strict_types=1);

namespace App\Tests\Application\Run;

use App\Application\Run\RunRepository;
use App\Domain\Run\RunStatus;
use App\Domain\Run\RunType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** markStarted lands after the last batch is dispatched; a pause or cancel that arrived in between must not be undone by it. */
#[CoversClass(RunRepository::class)]
final class RunRepositoryTest extends KernelTestCase
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

    public function testMarkStartedOnlyPromotesADispatchingRun(): void
    {
        $repo = new RunRepository($this->db);
        $dispatching = $this->insertRun(RunStatus::Dispatching);
        $paused = $this->insertRun(RunStatus::Paused);
        $cancelled = $this->insertRun(RunStatus::Cancelled);

        foreach ([$dispatching, $paused, $cancelled] as $id) {
            $repo->markStarted($id, 42);
        }

        $pausedRow = $repo->find($paused);
        self::assertNotNull($pausedRow);
        self::assertSame(RunStatus::Running, $repo->find($dispatching)?->status);
        self::assertSame(RunStatus::Paused, $pausedRow->status, 'a pause during dispatch survives');
        self::assertSame(RunStatus::Cancelled, $repo->find($cancelled)?->status, 'a cancel during dispatch survives');
        self::assertSame(42, $pausedRow->totalJobs, 'total_jobs is written regardless');
    }

    private function insertRun(RunStatus $status): string
    {
        $eventId = $this->db->fetchOne('SELECT MIN(id) FROM events');
        if (!is_numeric($eventId)) {
            self::markTestSkipped('arena not seeded');
        }
        $id = Uuid::v7()->toRfc4122();
        $this->db->executeStatement(
            'INSERT INTO runs (id, number, type, status, event_id, selection, params, total_jobs, completed_jobs, skipped_jobs, dead_lettered_jobs, created_at, updated_at) VALUES (UUID_TO_BIN(?), ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, NOW(), NOW())',
            [$id, random_int(900_000, 999_999), RunType::Reprice->value, $status->value, (int) $eventId, '{}', '{}'],
        );

        return $id;
    }
}
