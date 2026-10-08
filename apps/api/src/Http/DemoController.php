<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Arena\ArenaSeeder;
use App\Application\Platform\PlatformRepository;
use App\Application\Realtime\EventRecorder;
use App\Application\Run\RunConflictException;
use App\Application\Run\RunRepository;
use App\Infrastructure\Redis\RedisFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController
{
    /** Truncate everything and rebuild the arena. The dashboard reloads its seats payload on run.reset. */
    #[Route('/api/demo/reset', name: 'api_demo_reset', methods: ['POST'])]
    public function reset(ArenaSeeder $seeder, RunRepository $runs, PlatformRepository $platforms, RedisFactory $redis, EventRecorder $events): JsonResponse
    {
        if (null !== $runs->active()) {
            throw new RunConflictException('cancel the active run before resetting');
        }
        $seeder->truncate();
        $result = $seeder->seed();
        $platforms->syncToRedis();
        $r = $redis->get();
        foreach (['run:*', 'worker:*:completions', 'platform:*:stats', 'webhook_conflicts', 'events', 'autoscale:last_decision'] as $pattern) {
            foreach ($r->keys($pattern) as $key) {
                $r->del(substr((string) $key, \strlen('turnstile:')));
            }
        }
        $events->push('demo.reset', ['seats' => $result['seats']]);

        return new JsonResponse($result);
    }
}
