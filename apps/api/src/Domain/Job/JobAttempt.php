<?php

declare(strict_types=1);

namespace App\Domain\Job;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'job_attempts')]
#[ORM\UniqueConstraint(name: 'uq_job_attempts_job_no', columns: ['job_id', 'attempt_no'])]
#[ORM\Index(name: 'idx_job_attempts_outcome_started', columns: ['outcome', 'started_at'])]
#[ORM\Index(name: 'idx_job_attempts_job', columns: ['job_id'])]
class JobAttempt
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Job::class), ORM\JoinColumn(nullable: false)]
    public private(set) Job $job;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    public private(set) int $attemptNo;

    #[ORM\Column(length: 64)]
    public private(set) string $workerId;

    #[ORM\Column(length: 20, nullable: true, enumType: JobOutcome::class)]
    public private(set) ?JobOutcome $outcome = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    public private(set) ?int $httpStatus = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    public private(set) ?int $latencyMs = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    public private(set) ?int $retryAfterMs = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $finishedAt = null;
}
