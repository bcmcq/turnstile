<?php

declare(strict_types=1);

namespace App\Domain\Venue;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'venues')]
#[ORM\UniqueConstraint(name: 'uq_venues_code', columns: ['code'])]
class Venue
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 32)]
        public private(set) string $code,
        #[ORM\Column(length: 120)]
        public private(set) string $name,
        #[ORM\Column(length: 8, enumType: ArenaSize::class, options: ['default' => 'demo'])]
        public private(set) ArenaSize $arenaSize,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }
}
