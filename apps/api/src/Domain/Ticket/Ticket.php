<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Domain\Event\Event;
use App\Domain\Platform\Platform;
use App\Domain\Venue\Seat;
use App\Domain\Venue\Section;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Schema only: the seeder bulk-inserts and TicketRepository does the version-checked writes with raw DBAL. */
#[ORM\Entity]
#[ORM\Table(name: 'tickets')]
#[ORM\UniqueConstraint(name: 'uq_tickets_event_seat', columns: ['event_id', 'seat_id'])]
#[ORM\UniqueConstraint(name: 'uq_tickets_platform_ref', columns: ['platform_id', 'external_ref'])]
#[ORM\Index(name: 'idx_tickets_event_section_status', columns: ['event_id', 'section_id', 'status'])]
#[ORM\Index(name: 'idx_tickets_platform_status', columns: ['platform_id', 'status'])]
#[ORM\UniqueConstraint(name: 'uq_tickets_barcode', columns: ['barcode'])]
#[ORM\Index(name: 'idx_tickets_event', columns: ['event_id'])]
#[ORM\Index(name: 'idx_tickets_seat', columns: ['seat_id'])]
#[ORM\Index(name: 'idx_tickets_section', columns: ['section_id'])]
#[ORM\Index(name: 'idx_tickets_platform', columns: ['platform_id'])]
class Ticket
{
    #[ORM\Id, ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    public private(set) int $id;

    #[ORM\ManyToOne(targetEntity: Event::class), ORM\JoinColumn(nullable: false)]
    public private(set) Event $event;

    #[ORM\ManyToOne(targetEntity: Seat::class), ORM\JoinColumn(nullable: false)]
    public private(set) Seat $seat;

    /** Denormalized from the seat so the fan-out query never joins. */
    #[ORM\ManyToOne(targetEntity: Section::class), ORM\JoinColumn(nullable: false)]
    public private(set) Section $section;

    #[ORM\Column(length: 12, enumType: TicketStatus::class)]
    public private(set) TicketStatus $status;

    #[ORM\ManyToOne(targetEntity: Platform::class), ORM\JoinColumn(nullable: true)]
    public private(set) ?Platform $platform = null;

    #[ORM\Column(length: 64, nullable: true)]
    public private(set) ?string $externalRef = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $faceValueCents;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $priceCents;

    #[ORM\Column(length: 3)]
    public private(set) string $currency = 'USD';

    #[ORM\Column(length: 20)]
    public private(set) string $barcode;

    /** Optimistic lock. Doctrine checks it on flush; raw UPDATEs must add "AND version = ?" themselves. */
    #[ORM\Version, ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $version = 1;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public private(set) ?Uuid $lastRunId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;
}
