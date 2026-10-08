<?php

declare(strict_types=1);

namespace App\Application\Run\Dto;

use App\Domain\Run\RunStatus;
use App\Domain\Run\RunType;

final readonly class RunView
{
    /**
     * @param array{sections: list<int>, tickets: list<int>} $selection
     * @param array<string, mixed>                           $params
     * @param array<string, int>                             $counters
     */
    public function __construct(
        public string $id,
        public int $number,
        public RunType $type,
        public RunStatus $status,
        public ?string $targetPlatform,
        public array $selection,
        public array $params,
        public ?string $replayOfId,
        public int $totalJobs,
        public array $counters,
        public ?string $startedAt,
        public ?string $pausedAt,
        public ?string $finishedAt,
        public string $createdAt,
    ) {
    }
}
