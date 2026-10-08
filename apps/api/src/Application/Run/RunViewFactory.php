<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Application\Job\JobRepository;
use App\Application\Platform\PlatformRepository;
use App\Application\Realtime\RunCounters;
use App\Application\Run\Dto\RunView;

final readonly class RunViewFactory
{
    public function __construct(private RunCounters $counters, private JobRepository $jobs, private PlatformRepository $platforms)
    {
    }

    public function make(RunRow $run): RunView
    {
        return new RunView(
            id: $run->id,
            number: $run->number,
            type: $run->type,
            status: $run->status,
            targetPlatform: null === $run->targetPlatformId ? null : $this->platforms->byId($run->targetPlatformId)->code->value,
            selection: $run->selection,
            params: $run->params,
            replayOfId: $run->replayOfId,
            totalJobs: $run->totalJobs,
            counters: array_merge($this->jobs->countsByStatus($run->id), $this->counters->all($run->id)),
            startedAt: $run->startedAt,
            pausedAt: $run->pausedAt,
            finishedAt: $run->finishedAt,
            createdAt: $run->createdAt,
        );
    }
}
