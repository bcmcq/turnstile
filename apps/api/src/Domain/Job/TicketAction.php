<?php

declare(strict_types=1);

namespace App\Domain\Job;

use App\Domain\Platform\Platform;
use App\Domain\Run\Run;
use App\Domain\Run\RunType;
use App\Domain\Ticket\Ticket;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Idempotency ledger: one applied action per ticket per run. The unique index is the guard. Schema only: Ledger writes it with raw DBAL. */
#[ORM\Entity]
#[ORM\Table(name: 'ticket_actions')]
#[ORM\UniqueConstraint(name: 'uq_ticket_actions_ticket_run', columns: ['ticket_id', 'run_id'])]
#[ORM\Index(name: 'idx_ticket_actions_run_state', columns: ['run_id', 'state'])]
#[ORM\Index(name: 'idx_ticket_actions_ticket_created', columns: ['ticket_id', 'created_at'])]
#[ORM\Index(name: 'idx_ticket_actions_ticket', columns: ['ticket_id'])]
#[ORM\Index(name: 'idx_ticket_actions_run', columns: ['run_id'])]
#[ORM\Index(name: 'idx_ticket_actions_job', columns: ['job_id'])]
#[ORM\Index(name: 'idx_ticket_actions_from_platform', columns: ['from_platform_id'])]
#[ORM\Index(name: 'idx_ticket_actions_to_platform', columns: ['to_platform_id'])]
class TicketAction
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class), ORM\JoinColumn(nullable: false)]
    public private(set) Ticket $ticket;

    #[ORM\ManyToOne(targetEntity: Run::class), ORM\JoinColumn(nullable: false)]
    public private(set) Run $run;

    #[ORM\ManyToOne(targetEntity: Job::class), ORM\JoinColumn(nullable: false)]
    public private(set) Job $job;

    #[ORM\Column(length: 16, enumType: RunType::class)]
    public private(set) RunType $action;

    #[ORM\Column(length: 8, enumType: ActionState::class)]
    public private(set) ActionState $state = ActionState::Pending;

    /** Sent to the platform as Idempotency-Key. Deterministic: uuid5(runId, "ticket:{id}:{action}"). */
    #[ORM\Column(length: 36)]
    public private(set) string $idempotencyKey;

    #[ORM\ManyToOne(targetEntity: Platform::class), ORM\JoinColumn(nullable: true)]
    public private(set) ?Platform $fromPlatform = null;

    #[ORM\ManyToOne(targetEntity: Platform::class), ORM\JoinColumn(nullable: true)]
    public private(set) ?Platform $toPlatform = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    public private(set) ?int $fromPriceCents = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    public private(set) ?int $toPriceCents = null;

    #[ORM\Column(length: 20, nullable: true)]
    public private(set) ?string $fromBarcode = null;

    #[ORM\Column(length: 20, nullable: true)]
    public private(set) ?string $toBarcode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;
}
