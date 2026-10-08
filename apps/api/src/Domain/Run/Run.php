<?php

declare(strict_types=1);

namespace App\Domain\Run;

use App\Domain\Event\Event;
use App\Domain\Platform\Platform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-type Selection array{sections: list<int>, tickets: list<int>}
 */
#[ORM\Entity]
#[ORM\Table(name: 'runs')]
#[ORM\UniqueConstraint(name: 'uq_runs_number', columns: ['number'])]
#[ORM\Index(name: 'idx_runs_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_runs_replay_of', columns: ['replay_of_id'])]
#[ORM\Index(name: 'idx_runs_event', columns: ['event_id'])]
#[ORM\Index(name: 'idx_runs_target_platform', columns: ['target_platform_id'])]
class Run
{
    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public readonly Uuid $id;

    /** Human number shown as "#41". Assigned by the application; one run at a time makes this race-free. */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $number;

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    public private(set) RunStatus $status = RunStatus::Pending;

    #[ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(nullable: true)]
    public private(set) ?Run $replayOf = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $totalJobs = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $completedJobs = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $failedJobs = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $skippedJobs = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    public private(set) int $deadLetteredJobs = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    /**
     * @param Selection            $selection
     * @param array<string, mixed> $params    action-specific, validated by the typed DTO for the run type
     */
    public function __construct(
        int $number,
        #[ORM\Column(length: 16, enumType: RunType::class)]
        public private(set) RunType $type,
        #[ORM\ManyToOne(targetEntity: Event::class), ORM\JoinColumn(nullable: false)]
        public private(set) Event $event,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $selection,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $params = [],
        #[ORM\ManyToOne(targetEntity: Platform::class), ORM\JoinColumn(nullable: true)]
        public private(set) ?Platform $targetPlatform = null,
    ) {
        $this->id = Uuid::v7();
        $this->number = $number;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }
}
