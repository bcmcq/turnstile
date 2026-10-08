<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Job\JobRepository;
use App\Application\Run\RunControl;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class JobController
{
    public function __construct(private readonly JobRepository $jobs)
    {
    }

    /** Job with its attempt trace: the "#1 429 → +1s → #2 …" timeline. */
    #[Route('/api/jobs/{id}', name: 'api_jobs_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $job = $this->jobs->find($id) ?? throw new NotFoundHttpException('job not found');

        return new JsonResponse(['job' => $job, 'attempts' => $this->jobs->attempts($job->id), 'idempotencyKey' => $this->jobs->idempotencyKey($job->id)]);
    }

    /** Same trace, addressed by ticket: the latest job that touched the seat. */
    #[Route('/api/tickets/{ticketId}/job', name: 'api_tickets_job', methods: ['GET'], requirements: ['ticketId' => '\d+'])]
    public function latestForTicket(int $ticketId): JsonResponse
    {
        $job = $this->jobs->latestForTicket($ticketId) ?? throw new NotFoundHttpException('no job has touched this ticket yet');

        return new JsonResponse(['job' => $job, 'attempts' => $this->jobs->attempts($job->id), 'idempotencyKey' => $this->jobs->idempotencyKey($job->id)]);
    }

    #[Route('/api/jobs/{id}/retry', name: 'api_jobs_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id, RunControl $control): JsonResponse
    {
        $control->retryJob($id);

        return new JsonResponse(['requeued' => 1]);
    }
}
