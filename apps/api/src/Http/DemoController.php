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
        // Not the events stream: deleting it would drop the publisher's consumer group.
        foreach (['run:*', 'worker:*:completions', 'platform:*:stats', 'webhook_conflicts', 'autoscale:last_decision'] as $pattern) {
            foreach ($r->keys($pattern) as $key) {
                $r->del(substr((string) $key, \strlen('turnstile:')));
            }
        }
        // Queued messages from before the reset would collide with the re-numbered jobs. Trim the streams
        // (keeps the consumer groups the workers hold) and drop the delayed-retry sets.
        foreach (['turnstile_control', 'turnstile_platform_tixhub', 'turnstile_platform_seatswap', 'turnstile_platform_passmarket', 'turnstile_platform_house'] as $stream) {
            $r->rawCommand('XTRIM', $stream, 'MAXLEN', '0');
            $r->rawCommand('DEL', $stream . '__queue');
        }
        $events->push('demo.reset', ['seats' => $result['seats']]);

        return new JsonResponse($result);
    }
}
