<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Venue\Venue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'idx_events_venue_starts', columns: ['venue_id', 'starts_at'])]
#[ORM\Index(name: 'idx_events_venue', columns: ['venue_id'])]
class Event
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 12, enumType: EventStatus::class)]
    public private(set) EventStatus $status = EventStatus::OnSale;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Venue::class), ORM\JoinColumn(nullable: false)]
        public private(set) Venue $venue,
        #[ORM\Column(length: 160)]
        public private(set) string $name,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        public private(set) \DateTimeImmutable $startsAt,
    ) {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }
}
