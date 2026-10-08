<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Domain\Ticket\TicketStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Hot-path ticket reads and the version-checked write that is the optimistic lock. */
final readonly class TicketRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function find(int $id): ?TicketRow
    {
        /** @var array{id: int, event_id: int, section_id: int, status: string, platform_id: int|null, external_ref: string|null, face_value_cents: int, price_cents: int, barcode: string, version: int}|false $r */
        $r = $this->db->fetchAssociative('SELECT id, event_id, section_id, status, platform_id, external_ref, face_value_cents, price_cents, barcode, version FROM tickets WHERE id = ?', [$id]);

        return false === $r ? null : new TicketRow((int) $r['id'], (int) $r['event_id'], (int) $r['section_id'], TicketStatus::from($r['status']), null === $r['platform_id'] ? null : (int) $r['platform_id'], $r['external_ref'], (int) $r['face_value_cents'], (int) $r['price_cents'], $r['barcode'], (int) $r['version']);
    }

    /**
     * UPDATE … WHERE id = ? AND version = ?. Returns false when another writer got there first.
     */
    public function apply(TicketRow $ticket, TicketChange $change, ?string $runId): bool
    {
        $set = ['version = version + 1', 'updated_at = NOW()'];
        $params = [];
        if (null !== $change->status) {
            $set[] = 'status = ?';
            $params[] = $change->status->value;
        }
        if (null !== $change->listing) {
            $set[] = 'platform_id = ?';
            $params[] = $change->listing['platformId'];
            $set[] = 'external_ref = ?';
            $params[] = $change->listing['externalRef'];
        }
        if (null !== $change->priceCents) {
            $set[] = 'price_cents = ?';
            $params[] = $change->priceCents;
        }
        if (null !== $change->barcode) {
            $set[] = 'barcode = ?';
            $params[] = $change->barcode;
        }
        if (null !== $runId) {
            $set[] = 'last_run_id = ?';
            $params[] = Uuid::fromString($runId)->toBinary();
        }
        array_push($params, $ticket->id, $ticket->version);

        return 1 === $this->db->executeStatement('UPDATE tickets SET ' . implode(', ', $set) . ' WHERE id = ? AND version = ?', $params);
    }
}
