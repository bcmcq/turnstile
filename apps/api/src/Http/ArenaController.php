<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Arena\BootstrapQuery;
use App\Application\Arena\SeatsPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArenaController
{
    #[Route('/api/bootstrap', name: 'api_bootstrap', methods: ['GET'])]
    public function bootstrap(BootstrapQuery $query): JsonResponse
    {
        return new JsonResponse($query());
    }

    #[Route('/api/seats', name: 'api_seats', methods: ['GET'])]
    public function seats(SeatsPayload $payload, BootstrapQuery $query): Response
    {
        $body = $payload->build($query()->eventId);

        return new Response($body, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
