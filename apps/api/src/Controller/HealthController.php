<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $db = true;
        try {
            $this->db->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            $db = false;
        }

        return new JsonResponse(['status' => $db ? 'ok' : 'degraded', 'db' => $db, 'php' => PHP_VERSION], $db ? 200 : 503);
    }
}
