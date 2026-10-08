<?php

declare(strict_types=1);

namespace App\Application\Arena\Dto;

/** Everything the dashboard needs on first paint, except the seats themselves (GET /api/seats). */
final readonly class ArenaBootstrap
{
    /**
     * @param list<SectionView>  $sections
     * @param list<PlatformView> $platforms
     * @param array<string, int> $ticketCounts     keyed by TicketStatus value
     * @param array<string, int> $listedByPlatform keyed by PlatformCode value
     */
    public function __construct(
        public string $venueName,
        public int $eventId,
        public string $eventName,
        public float $arenaWidth,
        public float $arenaHeight,
        public float $seatPitch,
        public int $seatCount,
        public array $sections,
        public array $platforms,
        public array $ticketCounts,
        public array $listedByPlatform,
        public ?string $currentRunId,
        public bool $autoscaleEnabled,
    ) {
    }
}
