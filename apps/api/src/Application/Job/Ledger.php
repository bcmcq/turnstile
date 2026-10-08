<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Domain\Job\ActionState;
use App\Domain\Run\RunType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * The idempotency ledger (ticket_actions). claim() inserts a pending row before any platform call;
 * the unique index on (ticket_id, run_id) is the guard. A retry of the same job finds its own pending
 * row and carries on; a replay finds an applied row and stops.
 */
final readonly class Ledger
{
    public function __construct(private Connection $db)
    {
    }

    public static function idempotencyKey(string $runId, int $ticketId, RunType $action): string
    {
        return Uuid::v5(Uuid::fromString($runId), "ticket:{$ticketId}:{$action->value}")->toRfc4122();
    }

    public function claim(int $ticketId, string $runId, int $jobId, RunType $action, string $idempotencyKey): LedgerClaim
    {
        $run = Uuid::fromString($runId)->toBinary();
        try {
            $this->db->executeStatement(
                'INSERT INTO ticket_actions (ticket_id, run_id, job_id, action, state, idempotency_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$ticketId, $run, $jobId, $action->value, ActionState::Pending->value, $idempotencyKey],
            );

            return new LedgerClaim((int) $this->db->lastInsertId(), ActionState::Pending);
        } catch (UniqueConstraintViolationException) {
            /** @var array{id: int, state: string}|false $existing */
            $existing = $this->db->fetchAssociative('SELECT id, state FROM ticket_actions WHERE ticket_id = ? AND run_id = ?', [$ticketId, $run]);
            if (false === $existing) {
                throw new \RuntimeException("Ledger row for ticket {$ticketId} in run {$runId} vanished");
            }
            // A retried job re-attaches to its pending row (the job id may differ after a manual retry).
            $this->db->executeStatement('UPDATE ticket_actions SET job_id = ?, updated_at = NOW() WHERE id = ? AND state = ?', [$jobId, $existing['id'], ActionState::Pending->value]);

            return new LedgerClaim((int) $existing['id'], ActionState::from($existing['state']));
        }
    }

    public function apply(int $claimId, TicketRow $before, TicketChange $change): void
    {
        $this->db->executeStatement(
            'UPDATE ticket_actions SET state = ?, from_platform_id = ?, to_platform_id = ?, from_price_cents = ?, to_price_cents = ?, from_barcode = ?, to_barcode = ?, updated_at = NOW() WHERE id = ?',
            [
                ActionState::Applied->value,
                $before->platformId,
                null === $change->listing ? $before->platformId : $change->listing['platformId'],
                $before->priceCents,
                $change->priceCents ?? $before->priceCents,
                $before->barcode,
                $change->barcode ?? $before->barcode,
                $claimId,
            ],
        );
    }

    /** Give the key back when the action cannot apply (ticket sold meanwhile) so the row does not read as "applied". */
    public function release(int $claimId): void
    {
        $this->db->executeStatement('DELETE FROM ticket_actions WHERE id = ? AND state = ?', [$claimId, ActionState::Pending->value]);
    }
}
