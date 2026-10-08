<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Domain\Event\Event;
use App\Domain\Platform\Platform;
use App\Domain\Platform\PlatformCode;
use App\Domain\Ticket\TicketStatus;
use App\Domain\Venue\Section;
use App\Domain\Venue\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** Builds the whole arena. Small tables go through the ORM, seats and tickets through raw batched INSERTs. */
final class ArenaSeeder
{
    private const int BATCH = 5_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly string $mocksBaseUrl,
    ) {
    }

    public function isSeeded(): bool
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM venues');

        return is_numeric($count) && (int) $count > 0;
    }

    /** @return array{seats: int, sections: int} */
    public function seed(): array
    {
        $geometry = new ArenaGeometry();
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $venue = new Venue('arena-1', 'Turnstile Arena');
        $event = new Event($venue, 'Home Opener', new \DateTimeImmutable('+30 days 19:00'));
        $this->em->persist($venue);
        $this->em->persist($event);

        foreach ($this->platforms() as $platform) {
            $this->em->persist($platform);
        }

        $sections = [];
        foreach ($geometry->sections as $shape) {
            $section = new Section($venue, $shape->code, $shape->tier, $shape->geometry());
            $this->em->persist($section);
            $sections[] = $section;
        }
        $this->em->flush();

        // Seats and tickets: explicit ids so tickets can reference seats without a round trip.
        $seatRows = [];
        $ticketRows = [];
        $counts = [];
        $id = 0;
        foreach ($geometry->seats as $seat) {
            ++$id;
            $section = $sections[$seat->sectionIndex];
            $sectionId = (int) $section->id;
            $counts[$sectionId] = ($counts[$sectionId] ?? 0) + 1;
            $face = $section->tier->faceValueCents();
            $seatRows[] = \sprintf(
                "(%d,%d,%s,%d,'%s',%d,%d,'%s')",
                $id,
                $sectionId,
                $this->db->quote($seat->rowLabel),
                $seat->seatNumber,
                $seat->type->value,
                (int) round($seat->x * 10),
                (int) round($seat->y * 10),
                $now,
            );
            $ticketRows[] = \sprintf(
                "(%d,%d,%d,%d,'%s',NULL,NULL,%d,%d,'USD','HS-%04X-%06d',1,NULL,'%s','%s')",
                $id,
                (int) $event->id,
                $id,
                $sectionId,
                TicketStatus::Available->value,
                $face,
                $face,
                crc32((string) $id) & 0xFFFF,
                $id,
                $now,
                $now,
            );
            if (self::BATCH === \count($seatRows)) {
                $this->flushRows($seatRows, $ticketRows);
            }
        }
        $this->flushRows($seatRows, $ticketRows);

        foreach ($counts as $sectionId => $count) {
            $this->db->executeStatement('UPDATE sections SET seat_count = ? WHERE id = ?', [$count, $sectionId]);
        }

        return ['seats' => $id, 'sections' => \count($sections)];
    }

    public function truncate(): void
    {
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['webhook_deliveries', 'ticket_actions', 'job_attempts', 'jobs', 'runs', 'tickets', 'seats', 'sections', 'events', 'platforms', 'venues'] as $table) {
            $this->db->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param list<string> $seatRows
     * @param list<string> $ticketRows
     */
    private function flushRows(array &$seatRows, array &$ticketRows): void
    {
        if ([] === $seatRows) {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO seats (id, section_id, row_label, seat_number, seat_type, map_x, map_y, created_at) VALUES ' . implode(',', $seatRows),
        );
        $this->db->executeStatement(
            'INSERT INTO tickets (id, event_id, seat_id, section_id, status, platform_id, external_ref, face_value_cents, price_cents, currency, barcode, version, last_run_id, created_at, updated_at) VALUES ' . implode(',', $ticketRows),
        );
        $seatRows = [];
        $ticketRows = [];
    }

    /** @return list<Platform> */
    private function platforms(): array
    {
        $secret = static fn (PlatformCode $c): string => hash('sha256', 'turnstile-webhook-' . $c->value);

        return [
            new Platform(PlatformCode::TixHub, $this->mocksBaseUrl . '/tixhub', rateLimitPerMin: 3_000, feeBps: 1_000, minPriceCents: 500, webhookSecret: $secret(PlatformCode::TixHub)),
            new Platform(PlatformCode::SeatSwap, $this->mocksBaseUrl . '/seatswap', rateLimitPerMin: 3_000, feeBps: 1_250, minPriceCents: 500, webhookSecret: $secret(PlatformCode::SeatSwap)),
            new Platform(PlatformCode::PassMarket, $this->mocksBaseUrl . '/passmarket', rateLimitPerMin: 3_000, feeBps: 800, minPriceCents: 500, webhookSecret: $secret(PlatformCode::PassMarket)),
        ];
    }
}
