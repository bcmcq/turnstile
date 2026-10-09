<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Job\JobRepository;
use App\Application\Run\Dto\StartRunRequest;
use App\Application\Run\RunControl;
use App\Application\Run\RunRepository;
use App\Application\Run\RunStarter;
use App\Application\Run\RunViewFactory;
use App\Domain\Job\JobStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/runs', requirements: ['id' => '[0-9a-f-]{36}'])]
final class RunController
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly RunViewFactory $views,
        private readonly JobRepository $jobs,
    ) {
    }

    #[Route('', name: 'api_runs_start', methods: ['POST'])]
    public function start(#[MapRequestPayload(acceptFormat: 'json')] StartRunRequest $request, RunStarter $starter): JsonResponse
    {
        return new JsonResponse($this->views->make($starter->start($request)), 202);
    }

    #[Route('/current', name: 'api_runs_current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        $run = $this->runs->active() ?? $this->runs->latest();

        return new JsonResponse(null === $run ? null : $this->views->make($run));
    }

    #[Route('/{id}', name: 'api_runs_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return new JsonResponse($this->views->make($this->runs->find($id) ?? throw new NotFoundHttpException('run not found')));
    }

    #[Route('/{id}/pause', name: 'api_runs_pause', methods: ['POST'])]
    public function pause(string $id, RunControl $control): JsonResponse
    {
        return new JsonResponse($this->views->make($control->pause($id)));
    }

    #[Route('/{id}/resume', name: 'api_runs_resume', methods: ['POST'])]
    public function resume(string $id, RunControl $control): JsonResponse
    {
        return new JsonResponse($this->views->make($control->resume($id)));
    }

    #[Route('/{id}/cancel', name: 'api_runs_cancel', methods: ['POST'])]
    public function cancel(string $id, RunControl $control): JsonResponse
    {
        return new JsonResponse($this->views->make($control->cancel($id)));
    }

    #[Route('/{id}/retry-failed', name: 'api_runs_retry_failed', methods: ['POST'])]
    public function retryFailed(string $id, RunControl $control): JsonResponse
    {
        return new JsonResponse(['requeued' => $control->retryFailed($id)]);
    }

    #[Route('/{id}/jobs', name: 'api_runs_jobs', methods: ['GET'])]
    public function jobs(string $id, #[MapQueryParameter] ?string $status = null, #[MapQueryParameter] int $limit = 50, #[MapQueryParameter] int $offset = 0): JsonResponse
    {
        $this->runs->find($id) ?? throw new NotFoundHttpException('run not found');
        $jobStatus = null === $status ? null : (JobStatus::tryFrom($status) ?? throw new BadRequestHttpException('unknown job status'));

        return new JsonResponse($this->jobs->listByRun($id, $jobStatus, min(500, max(1, $limit)), max(0, $offset)));
    }
}
