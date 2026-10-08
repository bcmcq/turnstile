<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\WorkerRegistry;
use App\Application\Scaling\AutoscalePolicy;
use App\Application\Scaling\Dto\AutoscaleInput;
use App\Application\Scaling\Dto\ScaleInput;
use App\Application\Scaling\ScalerClientInterface;
use App\Application\Scaling\ScalerException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/workers')]
final class WorkersController
{
    public function __construct(
        private readonly WorkerRegistry $registry,
        private readonly ScalerClientInterface $scaler,
        private readonly AutoscalePolicy $autoscale,
        private readonly EventRecorder $events,
    ) {
    }

    #[Route('', name: 'api_workers', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['workers' => $this->registry->all(), 'queueDepth' => $this->autoscale->queueDepth(), 'autoscale' => $this->autoscale->state()]);
    }

    /** Manual scale: the sidecar clones or stops worker containers; cards appear when their heartbeats start. */
    #[Route('/scale', name: 'api_workers_scale', methods: ['POST'])]
    public function scale(#[MapRequestPayload(acceptFormat: 'json')] ScaleInput $input): JsonResponse
    {
        try {
            $result = $this->scaler->scale($input->workers);
        } catch (ScalerException $e) {
            throw new ServiceUnavailableHttpException(null, $e->getMessage(), $e);
        }
        $this->events->push('workers.scaled', ['target' => $result->target, 'before' => $result->before, 'after' => $result->after]);

        return new JsonResponse($result, 202);
    }

    #[Route('/autoscale', name: 'api_workers_autoscale', methods: ['PUT'])]
    public function autoscale(#[MapRequestPayload(acceptFormat: 'json')] AutoscaleInput $input): JsonResponse
    {
        $this->autoscale->setEnabled($input->enabled);
        $this->events->push('autoscale.toggled', ['enabled' => $input->enabled]);

        return new JsonResponse(['enabled' => $input->enabled]);
    }
}
