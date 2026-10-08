<?php

declare(strict_types=1);

namespace App\Domain\Job;

use App\Domain\Platform\Platform;
use App\Domain\Run\Run;
use App\Domain\Ticket\Ticket;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Rows are bulk-inserted at fan-out (raw DBAL) and updated per attempt. */
#[ORM\Entity]
#[ORM\Table(name: 'jobs')]
#[ORM\Index(name: 'idx_jobs_run_status', columns: ['run_id', 'status'])]
#[ORM\Index(name: 'idx_jobs_ticket', columns: ['ticket_id'])]
#[ORM\Index(name: 'idx_jobs_status_next', columns: ['status', 'next_attempt_at'])]
#[ORM\Index(name: 'idx_jobs_worker_updated', columns: ['worker_id', 'updated_at'])]
#[ORM\Index(name: 'idx_jobs_run', columns: ['run_id'])]
#[ORM\Index(name: 'idx_jobs_platform', columns: ['platform_id'])]
class Job
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Run::class), ORM\JoinColumn(nullable: false)]
    public private(set) Run $run;

    #[ORM\ManyToOne(targetEntity: Ticket::class), ORM\JoinColumn(nullable: false)]
    public private(set) Ticket $ticket;

    #[ORM\ManyToOne(targetEntity: Platform::class), ORM\JoinColumn(nullable: true)]
    public private(set) ?Platform $platform = null;

    #[ORM\Column(length: 16, enumType: JobStatus::class)]
    public private(set) JobStatus $status = JobStatus::Queued;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    public private(set) int $attempts = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    public private(set) int $maxAttempts = 5;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(length: 20, nullable: true, enumType: JobOutcome::class)]
    public private(set) ?JobOutcome $lastOutcome = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $lastError = null;

    #[ORM\Column(length: 64, nullable: true)]
    public private(set) ?string $workerId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    public private(set) ?int $durationMs = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;
}
