<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Domain\Run\RunStatus;
use App\Domain\Run\RunType;
use Doctrine\DBAL\Connection;

final readonly class RunRepository
{
    private const string SELECT = 'SELECT BIN_TO_UUID(r.id) AS id, r.number, r.type, r.status, r.event_id, r.target_platform_id, r.selection, r.params, BIN_TO_UUID(r.replay_of_id) AS replay_of_id, o.type AS replay_of_type, r.total_jobs, r.started_at, r.paused_at, r.finished_at, r.created_at FROM runs r LEFT JOIN runs o ON o.id = r.replay_of_id';

    public function __construct(private Connection $db)
    {
    }

    public function find(string $id): ?RunRow
    {
        return $this->one('r.id = UUID_TO_BIN(?)', [$id]);
    }

    public function active(): ?RunRow
    {
        return $this->one('r.status IN (?, ?, ?, ?) ORDER BY r.created_at DESC', [RunStatus::Pending->value, RunStatus::Dispatching->value, RunStatus::Running->value, RunStatus::Paused->value]);
    }

    public function latest(): ?RunRow
    {
        return $this->one('1 ORDER BY r.number DESC');
    }

    public function lastCompleted(): ?RunRow
    {
        return $this->one('r.status IN (?, ?) AND r.type <> ? ORDER BY r.number DESC', [RunStatus::Completed->value, RunStatus::CompletedWithFailures->value, RunType::Replay->value]);
    }

    public function nextNumber(): int
    {
        $max = $this->db->fetchOne('SELECT MAX(number) FROM runs');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * A terminal status only lands on a still-active run, and the caller learns whether it did: several workers
     * finalise concurrently and exactly one of them must publish run.finished.
     *
     * @return bool whether a row changed
     */
    public function setStatus(string $id, RunStatus $status): bool
    {
        $extra = match ($status) {
            RunStatus::Paused => ', paused_at = NOW()',
            RunStatus::Running => ', paused_at = NULL',
            RunStatus::Completed, RunStatus::CompletedWithFailures, RunStatus::Cancelled => ', finished_at = NOW()',
            default => '',
        };
        $guard = $status->isActive() ? '' : ' AND status IN (?, ?, ?, ?)';
        $params = [$status->value, $id, ...($status->isActive() ? [] : [RunStatus::Pending->value, RunStatus::Dispatching->value, RunStatus::Running->value, RunStatus::Paused->value])];

        return 1 === $this->db->executeStatement('UPDATE runs SET status = ?' . $extra . ', updated_at = NOW() WHERE id = UUID_TO_BIN(?)' . $guard, $params);
    }

    public function markStarted(string $id, int $totalJobs): void
    {
        $this->db->executeStatement('UPDATE runs SET status = ?, total_jobs = ?, started_at = NOW(), updated_at = NOW() WHERE id = UUID_TO_BIN(?)', [RunStatus::Running->value, $totalJobs, $id]);
    }

    /** @param array<string, int> $counters */
    public function writeCounters(string $id, array $counters): void
    {
        $this->db->executeStatement(
            'UPDATE runs SET completed_jobs = ?, skipped_jobs = ?, dead_lettered_jobs = ?, updated_at = NOW() WHERE id = UUID_TO_BIN(?)',
            [$counters['completed'] ?? 0, $counters['skipped'] ?? 0, $counters['dead_lettered'] ?? 0, $id],
        );
    }

    /**
     * The only place a raw row exists. Everything past this line is a RunRow, so nothing else needs an array shape.
     *
     * @param list<string> $params
     */
    private function one(string $where, array $params = []): ?RunRow
    {
        /** @var array{id: string, number: int|string, type: string, status: string, event_id: int|string, target_platform_id: int|string|null, selection: string, params: string, replay_of_id: string|null, replay_of_type: string|null, total_jobs: int|string, started_at: string|null, paused_at: string|null, finished_at: string|null, created_at: string}|false $r */
        $r = $this->db->fetchAssociative(self::SELECT . ' WHERE ' . $where . ' LIMIT 1', $params);
        if (false === $r) {
            return null;
        }
        /** @var array<string, mixed> $params */
        $params = json_decode($r['params'], true, 512, \JSON_THROW_ON_ERROR);

        return new RunRow(
            id: $r['id'],
            number: (int) $r['number'],
            type: RunType::from($r['type']),
            status: RunStatus::from($r['status']),
            eventId: (int) $r['event_id'],
            targetPlatformId: null === $r['target_platform_id'] ? null : (int) $r['target_platform_id'],
            selection: Selection::fromJson($r['selection']),
            params: $params,
            replayOfId: $r['replay_of_id'],
            replayOfType: null === $r['replay_of_type'] ? null : RunType::from($r['replay_of_type']),
            totalJobs: (int) $r['total_jobs'],
            startedAt: $r['started_at'],
            pausedAt: $r['paused_at'],
            finishedAt: $r['finished_at'],
            createdAt: $r['created_at'],
        );
    }
}
