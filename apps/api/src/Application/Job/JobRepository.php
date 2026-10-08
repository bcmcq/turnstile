<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Domain\Job\JobOutcome;
use App\Domain\Job\JobStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Hot-path writes for jobs and job_attempts, raw DBAL on purpose: thousands of small updates per run. */
final readonly class JobRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function find(int $id): ?JobRow
    {
        /** @var array{id: int, run_id: string, ticket_id: int, platform_id: int|null, status: string, attempts: int, max_attempts: int, last_outcome: string|null, last_error: string|null, worker_id: string|null, duration_ms: int|null}|false $r */
        $r = $this->db->fetchAssociative('SELECT id, BIN_TO_UUID(run_id) AS run_id, ticket_id, platform_id, status, attempts, max_attempts, last_outcome, last_error, worker_id, duration_ms FROM jobs WHERE id = ?', [$id]);

        return false === $r ? null : self::hydrate($r);
    }

    /**
     * @param list<array{id: int, ticketId: int, platformId: int|null}> $rows
     */
    public function bulkInsert(string $runId, array $rows): void
    {
        if ([] === $rows) {
            return;
        }
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $run = Uuid::fromString($runId)->toBinary();
        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?, ?, 0, 5, ?, ?)';
            array_push($params, $row['id'], $run, $row['ticketId'], $row['platformId'], JobStatus::Queued->value, $now, $now);
        }
        $this->db->executeStatement('INSERT INTO jobs (id, run_id, ticket_id, platform_id, status, attempts, max_attempts, created_at, updated_at) VALUES ' . implode(',', $values), $params);
    }

    public function nextId(): int
    {
        $max = $this->db->fetchOne('SELECT MAX(id) FROM jobs');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /** @return int the attempt number */
    public function openAttempt(int $jobId, string $workerId): int
    {
        $this->db->executeStatement('UPDATE jobs SET attempts = attempts + 1, status = ?, worker_id = ?, next_attempt_at = NULL, updated_at = NOW() WHERE id = ?', [JobStatus::InFlight->value, $workerId, $jobId]);
        $attemptNo = $this->db->fetchOne('SELECT attempts FROM jobs WHERE id = ?', [$jobId]);
        $attemptNo = is_numeric($attemptNo) ? (int) $attemptNo : 1;
        $this->db->executeStatement('INSERT INTO job_attempts (job_id, attempt_no, worker_id, started_at) VALUES (?, ?, ?, NOW(3))', [$jobId, $attemptNo, $workerId]);

        return $attemptNo;
    }

    public function closeAttempt(int $jobId, int $attemptNo, JobOutcome $outcome, ?int $httpStatus, ?int $latencyMs, ?int $retryAfterMs, ?string $error): void
    {
        $this->db->executeStatement(
            'UPDATE job_attempts SET outcome = ?, http_status = ?, latency_ms = ?, retry_after_ms = ?, error = ?, finished_at = NOW(3) WHERE job_id = ? AND attempt_no = ?',
            [$outcome->value, $httpStatus, $latencyMs, $retryAfterMs, null === $error ? null : mb_substr($error, 0, 500), $jobId, $attemptNo],
        );
    }

    public function markCompleted(int $jobId, int $durationMs): void
    {
        $this->setStatus($jobId, JobStatus::Completed, JobOutcome::Success, null, $durationMs);
    }

    public function markSkipped(int $jobId, JobOutcome $outcome, ?string $reason): void
    {
        $this->setStatus($jobId, JobStatus::Skipped, $outcome, $reason, null);
    }

    public function markRetryWait(int $jobId, JobOutcome $outcome, ?string $error, int $delayMs): void
    {
        $this->db->executeStatement(
            'UPDATE jobs SET status = ?, last_outcome = ?, last_error = ?, next_attempt_at = DATE_ADD(NOW(3), INTERVAL ? MICROSECOND), updated_at = NOW() WHERE id = ?',
            [JobStatus::RetryWait->value, $outcome->value, null === $error ? null : mb_substr($error, 0, 500), $delayMs * 1000, $jobId],
        );
    }

    public function markDeadLettered(int $jobId, ?JobOutcome $outcome, ?string $error): void
    {
        $this->setStatus($jobId, JobStatus::DeadLettered, $outcome, $error, null);
    }

    public function markCancelled(int $jobId): void
    {
        $this->setStatus($jobId, JobStatus::Cancelled, null, null, null);
    }

    /**
     * Back to the queue with five more attempts. Attempt numbers keep counting so the trace shows the whole
     * history; the ledger row (if any) is kept so a half-applied attempt still cannot double-apply.
     */
    public function resetForRetry(int $jobId): void
    {
        $this->db->executeStatement('UPDATE jobs SET status = ?, max_attempts = attempts + 5, last_outcome = NULL, last_error = NULL, next_attempt_at = NULL, updated_at = NOW() WHERE id = ?', [JobStatus::Queued->value, $jobId]);
    }

    /** @return array<string, int> keyed by JobStatus value, zero for absent statuses */
    public function countsByStatus(string $runId): array
    {
        $out = array_fill_keys(array_map(static fn (JobStatus $s): string => $s->value, JobStatus::cases()), 0);
        /** @var array<string, int|string> $rows */
        $rows = $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM jobs WHERE run_id = UUID_TO_BIN(?) GROUP BY status', [$runId]);
        foreach ($rows as $status => $count) {
            $out[$status] = (int) $count;
        }

        return $out;
    }

    /** @return list<int> */
    public function idsByStatus(string $runId, JobStatus $status): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->db->fetchFirstColumn('SELECT id FROM jobs WHERE run_id = UUID_TO_BIN(?) AND status = ? ORDER BY id', [$runId, $status->value]);

        return array_map(intval(...), $ids);
    }

    /** @return list<JobRow> */
    public function listByRun(string $runId, ?JobStatus $status, int $limit, int $offset): array
    {
        $sql = 'SELECT id, BIN_TO_UUID(run_id) AS run_id, ticket_id, platform_id, status, attempts, max_attempts, last_outcome, last_error, worker_id, duration_ms FROM jobs WHERE run_id = UUID_TO_BIN(?)';
        $params = [$runId];
        if (null !== $status) {
            $sql .= ' AND status = ?';
            $params[] = $status->value;
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $out = [];
        /** @var array{id: int, run_id: string, ticket_id: int, platform_id: int|null, status: string, attempts: int, max_attempts: int, last_outcome: string|null, last_error: string|null, worker_id: string|null, duration_ms: int|null} $r */
        foreach ($this->db->iterateAssociative($sql, $params) as $r) {
            $out[] = self::hydrate($r);
        }

        return $out;
    }

    /** @return list<array{attemptNo: int, workerId: string, outcome: string|null, httpStatus: int|null, latencyMs: int|null, retryAfterMs: int|null, error: string|null, startedAt: string, finishedAt: string|null}> */
    public function attempts(int $jobId): array
    {
        $out = [];
        /** @var array{attempt_no: int, worker_id: string, outcome: string|null, http_status: int|null, latency_ms: int|null, retry_after_ms: int|null, error: string|null, started_at: string, finished_at: string|null} $r */
        foreach ($this->db->iterateAssociative('SELECT attempt_no, worker_id, outcome, http_status, latency_ms, retry_after_ms, error, started_at, finished_at FROM job_attempts WHERE job_id = ? ORDER BY attempt_no', [$jobId]) as $r) {
            $out[] = ['attemptNo' => (int) $r['attempt_no'], 'workerId' => $r['worker_id'], 'outcome' => $r['outcome'], 'httpStatus' => null === $r['http_status'] ? null : (int) $r['http_status'], 'latencyMs' => null === $r['latency_ms'] ? null : (int) $r['latency_ms'], 'retryAfterMs' => null === $r['retry_after_ms'] ? null : (int) $r['retry_after_ms'], 'error' => $r['error'], 'startedAt' => $r['started_at'], 'finishedAt' => $r['finished_at']];
        }

        return $out;
    }

    private function setStatus(int $jobId, JobStatus $status, ?JobOutcome $outcome, ?string $error, ?int $durationMs): void
    {
        $this->db->executeStatement(
            'UPDATE jobs SET status = ?, last_outcome = COALESCE(?, last_outcome), last_error = ?, duration_ms = COALESCE(?, duration_ms), next_attempt_at = NULL, updated_at = NOW() WHERE id = ?',
            [$status->value, $outcome?->value, null === $error ? null : mb_substr($error, 0, 500), $durationMs, $jobId],
        );
    }

    /** @param array{id: int, run_id: string, ticket_id: int, platform_id: int|null, status: string, attempts: int, max_attempts: int, last_outcome: string|null, last_error: string|null, worker_id: string|null, duration_ms: int|null} $r */
    private static function hydrate(array $r): JobRow
    {
        return new JobRow((int) $r['id'], $r['run_id'], (int) $r['ticket_id'], null === $r['platform_id'] ? null : (int) $r['platform_id'], JobStatus::from($r['status']), (int) $r['attempts'], (int) $r['max_attempts'], null === $r['last_outcome'] ? null : JobOutcome::from($r['last_outcome']), $r['last_error'], $r['worker_id'], null === $r['duration_ms'] ? null : (int) $r['duration_ms']);
    }
}
