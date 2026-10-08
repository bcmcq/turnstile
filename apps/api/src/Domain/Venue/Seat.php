<?php

declare(strict_types=1);

namespace App\Domain\Venue;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Seats are bulk-inserted by the seeder with raw DBAL; this entity exists for reads and relations. */
#[ORM\Entity]
#[ORM\Table(name: 'seats')]
#[ORM\UniqueConstraint(name: 'uq_seats_position', columns: ['section_id', 'row_label', 'seat_number'])]
#[ORM\Index(name: 'idx_seats_section', columns: ['section_id'])]
class Seat
{
    #[ORM\Id, ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    public private(set) int $id;

    #[ORM\ManyToOne(targetEntity: Section::class), ORM\JoinColumn(nullable: false)]
    public private(set) Section $section;

    #[ORM\Column(length: 4)]
    public private(set) string $rowLabel;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    public private(set) int $seatNumber;

    #[ORM\Column(length: 10, enumType: SeatType::class)]
    public private(set) SeatType $seatType;

    /** Canvas coordinates in tenths of a logical unit (arena is 800 × 640 units). */
    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $mapX;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $mapY;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;
}
