<?php

declare(strict_types=1);

namespace App\Application\Run;

use App\Application\Realtime\EventRecorder;
use App\Application\Run\Dto\StartRunRequest;
use App\Application\Run\Message\RunStarted;
use App\Domain\Event\Event;
use App\Domain\Platform\Platform;
use App\Domain\Run\Run;
use App\Domain\Run\RunType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Validates the request against the current state, persists the Run, and hands fan-out to the control queue. */
final readonly class RunStarter
{
    public function __construct(
        private EntityManagerInterface $em,
        private RunRepository $runs,
        private MessageBusInterface $bus,
        private EventRecorder $events,
    ) {
    }

    public function start(StartRunRequest $request): RunRow
    {
        if (null !== $this->runs->active()) {
            throw new RunConflictException('A run is already active; wait for it to finish or cancel it');
        }

        $event = $this->em->getRepository(Event::class)->findOneBy([], ['id' => 'ASC']) ?? throw new \RuntimeException('No event seeded');
        $target = null;
        $params = [];
        $replayOf = null;
        $selection = ['sections' => $request->selection->sections, 'tickets' => $request->selection->tickets];

        switch ($request->type) {
            case RunType::Transfer:
                $target = $this->em->getRepository(Platform::class)->findOneBy(['code' => $request->targetPlatform ?? throw new RunConflictException('transfer needs a target platform')]);
                break;
            case RunType::Reprice:
                $params = ($request->reprice ?? throw new RunConflictException('reprice needs reprice parameters'))->toParams();
                break;
            case RunType::Replay:
                $origin = $this->runs->lastCompleted() ?? throw new RunConflictException('nothing to replay yet');
                $replayOf = $this->em->getReference(Run::class, \Symfony\Component\Uid\Uuid::fromString($origin->id));
                $params = ['limit' => $request->replayLimit];
                $selection = ['sections' => [], 'tickets' => []];
                break;
            case RunType::Fill:
                throw new RunConflictException('fill is done by the seeder; use POST /api/demo/reset');
            default:
                break;
        }
        if (RunType::Replay !== $request->type && $request->selection->isEmpty()) {
            throw new RunConflictException('select at least one section or seat');
        }

        $run = new Run($this->runs->nextNumber(), $request->type, $event, $selection, $params, $target);
        if (null !== $replayOf) {
            $run->setReplayOf($replayOf);
        }
        $this->em->persist($run);
        $this->em->flush();

        $this->events->push('run.created', ['runId' => $run->id->toRfc4122(), 'number' => $run->number, 'type' => $run->type->value]);
        $this->bus->dispatch(new RunStarted($run->id->toRfc4122()));

        return $this->runs->find($run->id->toRfc4122()) ?? throw new \LogicException('run vanished after flush');
    }
}
