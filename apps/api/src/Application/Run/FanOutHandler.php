<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Application\Job\JobRepository;
use App\Application\Job\Message\ProcessTicketJob;
use App\Application\Platform\PlatformRepository;
use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\RunCounters;
use App\Application\Run\Message\RunStarted;
use App\Domain\Job\JobStatus;
use App\Domain\Platform\PlatformCode;
use App\Domain\Run\RunStatus;
use App\Domain\Run\RunType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * One run → N jobs. Streams ticket ids (never hydrates entities), bulk-inserts job rows 1,000 at a time,
 * and dispatches one message per job onto the transport of the platform that job will call.
 */
#[AsMessageHandler]
final class FanOutHandler
{
    private const int BATCH = 1_000;

    public function __construct(
        private readonly Connection $db,
        private readonly RunRepository $runs,
        private readonly RunFlags $flags,
        private readonly JobRepository $jobs,
        private readonly PlatformRepository $platforms,
        private readonly RunCounters $counters,
        private readonly EventRecorder $events,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(RunStarted $message): void
    {
        $run = $this->runs->find($message->runId);
        if (null === $run || RunStatus::Pending !== $run->status) {
            return;
        }
        $this->runs->setStatus($run->id, RunStatus::Dispatching);
        $this->events->push('run.dispatching', ['runId' => $run->id, 'number' => $run->number]);

        $targetCode = null === $run->targetPlatformId ? null : $this->platforms->byId($run->targetPlatformId)->code;
        $nextId = $this->jobs->nextId();
        $total = 0;
        $batch = [];

        foreach ($this->ticketsFor($run) as [$ticketId, $currentPlatformCode]) {
            if ($this->flags->isCancelled($run->id)) {
                break;
            }
            $platform = self::platformFor($run->actionType(), $targetCode, $currentPlatformCode);
            $batch[] = ['id' => $nextId++, 'ticketId' => $ticketId, 'platformId' => null === $platform ? null : $this->platforms->byCode($platform)->id, 'platform' => $platform];
            if (self::BATCH === \count($batch)) {
                $total += $this->flush($run->id, $batch);
                $batch = [];
            }
        }
        $total += $this->flush($run->id, $batch);

        $this->counters->reset($run->id);
        $this->runs->markStarted($run->id, $total);
        $this->events->push('run.started', ['runId' => $run->id, 'number' => $run->number, 'type' => $run->type->value, 'totalJobs' => $total, 'sections' => implode(',', $run->selection['sections'])]);
        if (0 === $total) {
            $this->runs->setStatus($run->id, RunStatus::Completed);
            $this->events->push('run.finished', ['runId' => $run->id, 'number' => $run->number, 'status' => RunStatus::Completed->value, 'completed' => 0, 'skipped' => 0, 'deadLettered' => 0, 'conflicts' => 0, 'soldDuringRun' => 0]);
        }
    }

    /**
     * @param list<array{id: int, ticketId: int, platformId: int|null, platform: PlatformCode|null}> $batch
     */
    private function flush(string $runId, array $batch): int
    {
        if ([] === $batch) {
            return 0;
        }
        $this->jobs->bulkInsert($runId, array_map(static fn (array $b): array => ['id' => $b['id'], 'ticketId' => $b['ticketId'], 'platformId' => $b['platformId']], $batch));
        foreach ($batch as $b) {
            $job = new ProcessTicketJob($b['id'], $runId, $b['ticketId'], $b['platform']);
            $this->bus->dispatch($job, [new TransportNamesStamp([$job->transport()])]);
        }

        return \count($batch);
    }

    /** @return iterable<array{int, PlatformCode|null}> ticket id and the platform it is currently listed on */
    private function ticketsFor(RunRow $run): iterable
    {
        if (RunType::Replay === $run->type) {
            $limit = is_numeric($run->params['limit'] ?? null) ? (int) $run->params['limit'] : 500;
            $sql = 'SELECT t.id, p.code FROM jobs j JOIN tickets t ON t.id = j.ticket_id LEFT JOIN platforms p ON p.id = t.platform_id WHERE j.run_id = UUID_TO_BIN(?) AND j.status = ? ORDER BY j.id DESC LIMIT ' . $limit;
            /** @var array{id: int, code: string|null} $r */
            foreach ($this->db->iterateAssociative($sql, [$run->replayOfId, JobStatus::Completed->value]) as $r) {
                yield [(int) $r['id'], null === $r['code'] ? null : PlatformCode::from($r['code'])];
            }

            return;
        }

        $sql = 'SELECT DISTINCT t.id, p.code FROM tickets t LEFT JOIN platforms p ON p.id = t.platform_id WHERE t.event_id = ? AND (';
        $params = [$run->eventId];
        $types = [ParameterType::INTEGER];
        $where = [];
        if ([] !== $run->selection['sections']) {
            $where[] = 't.section_id IN (?)';
            $params[] = $run->selection['sections'];
            $types[] = ArrayParameterType::INTEGER;
        }
        if ([] !== $run->selection['tickets']) {
            $where[] = 't.id IN (?)';
            $params[] = $run->selection['tickets'];
            $types[] = ArrayParameterType::INTEGER;
        }
        if ([] === $where) {
            return;
        }
        $sql .= implode(' OR ', $where) . ') ORDER BY t.id';
        /** @var array{id: int, code: string|null} $r */
        foreach ($this->db->iterateAssociative($sql, $params, $types) as $r) {
            yield [(int) $r['id'], null === $r['code'] ? null : PlatformCode::from($r['code'])];
        }
    }

    /** Which queue (and therefore which vendor) the job will hit. */
    private static function platformFor(RunType $action, ?PlatformCode $target, ?PlatformCode $current): ?PlatformCode
    {
        return match ($action) {
            RunType::Transfer => $target,
            RunType::Release, RunType::Reprice, RunType::Regenerate, RunType::CloseSection => $current,
            default => null,
        };
    }
}
