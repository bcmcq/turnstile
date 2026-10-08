<?php

declare(strict_types=1);

namespace App\Domain\Venue;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * @phpstan-type SectionGeometry array{angleStart: float, angleEnd: float, ringStart: int, ringEnd: int, labelX: float, labelY: float}
 */
#[ORM\Entity]
#[ORM\Table(name: 'sections')]
#[ORM\UniqueConstraint(name: 'uq_sections_venue_code', columns: ['venue_id', 'code'])]
#[ORM\Index(name: 'idx_sections_status', columns: ['status'])]
#[ORM\Index(name: 'idx_sections_venue', columns: ['venue_id'])]
class Section
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 8, enumType: SectionStatus::class)]
    public private(set) SectionStatus $status = SectionStatus::Open;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $seatCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    /** @param SectionGeometry $mapGeometry */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Venue::class), ORM\JoinColumn(nullable: false)]
        public private(set) Venue $venue,
        #[ORM\Column(length: 8)]
        public private(set) string $code,
        #[ORM\Column(length: 8, enumType: SectionTier::class)]
        public private(set) SectionTier $tier,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $mapGeometry,
    ) {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function setStatus(SectionStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
