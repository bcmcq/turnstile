<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Realtime\SnapshotBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MetricsController
{
    /** Same payload the publisher pushes on turnstile/metrics, for first paint and for `watch curl`. Series are empty here. */
    #[Route('/api/metrics', name: 'api_metrics', methods: ['GET'])]
    public function __invoke(SnapshotBuilder $snapshots): JsonResponse
    {
        return new JsonResponse($snapshots->build(0.0, 0, []));
    }
}
