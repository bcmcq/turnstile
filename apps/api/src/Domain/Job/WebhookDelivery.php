<?php

declare(strict_types=1);

namespace App\Domain\Job;

use App\Domain\Platform\Platform;
use App\Domain\Ticket\Ticket;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Replay guard for inbound marketplace webhooks. */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\UniqueConstraint(name: 'uq_webhook_deliveries_platform_delivery', columns: ['platform_id', 'delivery_id'])]
#[ORM\Index(name: 'idx_webhook_deliveries_ticket', columns: ['ticket_id'])]
#[ORM\Index(name: 'idx_webhook_deliveries_platform', columns: ['platform_id'])]
class WebhookDelivery
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $receivedAt;

    /** @param array<string, mixed> $payload */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Platform::class), ORM\JoinColumn(nullable: false)]
        public private(set) Platform $platform,
        #[ORM\Column(length: 36)]
        public private(set) string $deliveryId,
        #[ORM\Column(length: 40)]
        public private(set) string $event,
        #[ORM\ManyToOne(targetEntity: Ticket::class), ORM\JoinColumn(nullable: true)]
        public private(set) ?Ticket $ticket,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $payload,
        #[ORM\Column(length: 24, enumType: WebhookOutcome::class)]
        public private(set) WebhookOutcome $outcome,
    ) {
        $this->receivedAt = new \DateTimeImmutable();
    }
}
