<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Domain\Run\RunStatus;
use App\Domain\Run\RunType;

final readonly class RunRow
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public string $id,
        public int $number,
        public RunType $type,
        public RunStatus $status,
        public int $eventId,
        public ?int $targetPlatformId,
        public Selection $selection,
        public array $params,
        public ?string $replayOfId,
        public ?RunType $replayOfType,
        public int $totalJobs,
        public ?string $startedAt,
        public ?string $pausedAt,
        public ?string $finishedAt,
        public string $createdAt,
    ) {
    }

    /** The action to apply per ticket. A replay applies the original run's action. */
    public function actionType(): RunType
    {
        return RunType::Replay === $this->type ? ($this->replayOfType ?? throw new \LogicException('replay without origin')) : $this->type;
    }
}
