<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Domain\Ticket\TicketStatus;
use Doctrine\DBAL\Connection;

/**
 * Streams every ticket of the event as a compact JSON array of arrays. Built for ~100k rows, so the body is
 * assembled as a string rather than hydrated into PHP arrays, and Caddy gzips it on the way out.
 */
final readonly class SeatsPayload
{
    public const string COLUMNS = '["ticketId","sectionId","x","y","state","platformId","row","seat","priceCents"]';

    public function __construct(private Connection $db)
    {
    }

    public function build(int $eventId): string
    {
        $states = [];
        foreach (TicketStatus::cases() as $status) {
            $states[$status->value] = $status->code();
        }

        $sql = 'SELECT t.id, t.section_id, s.map_x, s.map_y, t.status, t.platform_id, s.row_label, s.seat_number, t.price_cents
                FROM tickets t JOIN seats s ON s.id = t.seat_id WHERE t.event_id = ? ORDER BY t.id';
        $out = '{"columns":' . self::COLUMNS . ',"rows":[';
        $first = true;
        /** @var array{0: int, 1: int, 2: int, 3: int, 4: string, 5: int|null, 6: string, 7: int, 8: int} $r */
        foreach ($this->db->iterateNumeric($sql, [$eventId]) as $r) {
            $out .= ($first ? '' : ',') . '[' . $r[0] . ',' . $r[1] . ',' . $r[2] . ',' . $r[3] . ',' . $states[$r[4]] . ',' . ($r[5] ?? 0) . ',' . json_encode($r[6], \JSON_THROW_ON_ERROR) . ',' . $r[7] . ',' . $r[8] . ']';
            $first = false;
        }

        return $out . ']}';
    }
}
