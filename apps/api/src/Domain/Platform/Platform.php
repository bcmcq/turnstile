<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'platforms')]
#[ORM\UniqueConstraint(name: 'uq_platforms_code', columns: ['code'])]
class Platform
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 7)]
    public private(set) string $color;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2)]
    public private(set) string $clientPaceRatio = '0.80';

    /** Chaos: probability the mock fails a call. Mirrored to Redis for the mocks. */
    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 3)]
    public private(set) string $failureRate = '0.000';

    /** Probability a list/reprice call is sniped by a buyer, which fires the listing.sold webhook. */
    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 3)]
    public private(set) string $buyerRate = '0.020';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 32, enumType: PlatformCode::class)]
        public private(set) PlatformCode $code,
        #[ORM\Column(length: 255)]
        public private(set) string $baseUrl,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        public private(set) int $rateLimitPerMin,
        #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
        public private(set) int $feeBps,
        #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
        public private(set) int $minPriceCents,
        #[ORM\Column(length: 64)]
        public private(set) string $webhookSecret,
    ) {
        $this->color = $code->color();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function name(): string
    {
        return $this->code->displayName();
    }

    public function listingPriceFor(int $faceValueCents): int
    {
        return max($this->minPriceCents, intdiv($faceValueCents * (10_000 + $this->feeBps), 10_000));
    }

    public function setFailureRate(float $rate): void
    {
        $this->failureRate = number_format(max(0.0, min(1.0, $rate)), 3, '.', '');
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setBuyerRate(float $rate): void
    {
        $this->buyerRate = number_format(max(0.0, min(1.0, $rate)), 3, '.', '');
        $this->updatedAt = new \DateTimeImmutable();
    }
}
