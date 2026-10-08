<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Application\Job\JobRepository;
use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\RunCounters;
use App\Domain\Run\RunStatus;
use App\Domain\Venue\SectionStatus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Called after every terminal job. When terminal counts reach total_jobs the run is finished. */
final class RunFinalizer
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly JobRepository $jobs,
        private readonly RunCounters $counters,
        private readonly EventRecorder $events,
        private readonly RunFlags $flags,
        private readonly Connection $db,
    ) {
    }

    public function check(string $runId): void
    {
        $run = $this->runs->find($runId);
        if (null === $run || !$run->status->isActive() || 0 === $run->totalJobs) {
            return;
        }
        $s = $this->jobs->countsByStatus($runId);
        $terminal = $s['completed'] + $s['skipped'] + $s['dead_lettered'] + $s['cancelled'];
        if ($terminal < $run->totalJobs) {
            return;
        }
        $c = $this->counters->all($runId);
        $status = match (true) {
            $this->flags->isCancelled($runId) => RunStatus::Cancelled,
            $s['dead_lettered'] > 0 => RunStatus::CompletedWithFailures,
            default => RunStatus::Completed,
        };
        $this->runs->setStatus($runId, $status);
        $this->runs->writeCounters($runId, $s);
        $this->flipSections($run, $status);
        $this->events->push('run.finished', ['runId' => $runId, 'number' => $run->number, 'status' => $status->value, 'completed' => $s['completed'], 'skipped' => $s['skipped'], 'deadLettered' => $s['dead_lettered'], 'conflicts' => $c['conflicts'], 'soldDuringRun' => $c['sold_during_run']]);
    }

    /** close_section / open_section flip the section rows once their tickets are done. */
    private function flipSections(RunRow $run, RunStatus $status): void
    {
        if (RunStatus::Cancelled === $status || [] === $run->selection['sections']) {
            return;
        }
        $sectionStatus = match ($run->type) {
            \App\Domain\Run\RunType::CloseSection => SectionStatus::Closed,
            \App\Domain\Run\RunType::OpenSection => SectionStatus::Open,
            default => null,
        };
        if (null === $sectionStatus) {
            return;
        }
        $this->db->executeStatement(
            'UPDATE sections SET status = ?, updated_at = NOW() WHERE id IN (?)',
            [$sectionStatus->value, $run->selection['sections']],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        foreach ($run->selection['sections'] as $sectionId) {
            $this->events->push('section.updated', ['sectionId' => $sectionId, 'status' => $sectionStatus->value, 'runId' => Uuid::fromString($run->id)->toRfc4122()]);
        }
    }
}
