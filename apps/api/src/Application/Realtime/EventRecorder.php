<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Infrastructure\Redis\RedisFactory;

/**
 * Workers never talk to Mercure. They append compact events to one Redis stream; the publisher
 * process folds the stream into batches every 250 ms (Publisher).
 */
final class EventRecorder
{
    public const string STREAM = 'events';
    private const int MAX_LENGTH = 50_000;

    public function __construct(private readonly RedisFactory $redis)
    {
    }

    /** @param array<string, int|float|string|bool|null> $payload */
    public function push(string $type, array $payload): void
    {
        $this->redis->get()->xAdd(self::STREAM, '*', [
            'type' => $type,
            'ts' => (string) (int) (microtime(true) * 1000),
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
        ], self::MAX_LENGTH, true);
    }
}
