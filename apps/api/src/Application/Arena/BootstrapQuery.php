<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Application\Arena\Dto\ArenaBootstrap;
use App\Application\Arena\Dto\PlatformView;
use App\Application\Arena\Dto\SectionView;
use App\Domain\Platform\PlatformCode;
use App\Domain\Venue\SectionStatus;
use App\Domain\Venue\SectionTier;
use Doctrine\DBAL\Connection;

final readonly class BootstrapQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function __invoke(): ArenaBootstrap
    {
        /** @var array{id: int, name: string, venue_name: string}|false $event */
        $event = $this->db->fetchAssociative('SELECT e.id, e.name, v.name AS venue_name FROM events e JOIN venues v ON v.id = e.venue_id ORDER BY e.id LIMIT 1');
        if (false === $event) {
            throw new \RuntimeException('Arena is not seeded; run bin/console turnstile:seed');
        }

        $sections = [];
        /** @var array{id: int, code: string, tier: string, status: string, seat_count: int, map_geometry: string} $row */
        foreach ($this->db->iterateAssociative('SELECT id, code, tier, status, seat_count, map_geometry FROM sections ORDER BY id') as $row) {
            /** @var array{angleStart: float, angleEnd: float, ringStart: int, ringEnd: int, labelX: float, labelY: float} $geometry */
            $geometry = json_decode($row['map_geometry'], true, 512, \JSON_THROW_ON_ERROR);
            $sections[] = new SectionView((int) $row['id'], $row['code'], SectionTier::from($row['tier']), SectionStatus::from($row['status']), (int) $row['seat_count'], $geometry);
        }

        $platforms = [];
        /** @var array{id: int, code: string, color: string, rate_limit_per_min: int, client_pace_ratio: string, fee_bps: int, failure_rate: string, buyer_rate: string} $row */
        foreach ($this->db->iterateAssociative('SELECT id, code, color, rate_limit_per_min, client_pace_ratio, fee_bps, failure_rate, buyer_rate FROM platforms ORDER BY id') as $row) {
            $code = PlatformCode::from($row['code']);
            $platforms[] = new PlatformView((int) $row['id'], $code, $code->displayName(), $row['color'], (int) $row['rate_limit_per_min'], (float) $row['client_pace_ratio'], (int) $row['fee_bps'], (float) $row['failure_rate'], (float) $row['buyer_rate']);
        }

        /** @var array<string, int|string> $byStatus */
        $byStatus = $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM tickets WHERE event_id = ? GROUP BY status', [$event['id']]);
        /** @var array<string, int|string> $byPlatform */
        $byPlatform = $this->db->fetchAllKeyValue('SELECT p.code, COUNT(*) FROM tickets t JOIN platforms p ON p.id = t.platform_id WHERE t.event_id = ? AND t.status = ? GROUP BY p.code', [$event['id'], 'listed']);

        return new ArenaBootstrap(
            venueName: $event['venue_name'],
            eventId: (int) $event['id'],
            eventName: $event['name'],
            arenaWidth: ArenaGeometry::WIDTH,
            arenaHeight: ArenaGeometry::HEIGHT,
            seatCount: array_sum(array_map(intval(...), $byStatus)),
            sections: $sections,
            platforms: $platforms,
            ticketCounts: array_map(intval(...), $byStatus),
            listedByPlatform: array_map(intval(...), $byPlatform),
            currentRunId: null,   // phase 2
            autoscaleEnabled: false, // phase 5
        );
    }
}
