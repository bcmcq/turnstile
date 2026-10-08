<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Infrastructure\Redis\RedisFactory;

/** Consumer-group reader for the worker event stream. One consumer (the publisher); ack after fold. */
final class EventStreamReader
{
    private const string GROUP = 'publisher';
    private bool $ready = false;

    public function __construct(private readonly RedisFactory $redis, private readonly string $workerId)
    {
    }

    /** @return list<StreamEvent> */
    public function read(int $count, int $blockMs): array
    {
        $redis = $this->redis->get();
        $this->setup($redis);
        /** @var array<string, array<string, array<string, string>>>|false $result */
        $result = $redis->xReadGroup(self::GROUP, $this->workerId, [EventRecorder::STREAM => '>'], $count, $blockMs);
        if (false === $result && str_contains((string) $redis->getLastError(), 'NOGROUP')) {
            // Stream or group vanished (flush, reset): recreate and carry on.
            $redis->clearLastError();
            $this->ready = false;
            $this->setup($redis);

            return [];
        }
        if (!\is_array($result) || [] === $result) {
            return [];
        }
        $events = [];
        $ids = [];
        foreach ($result as $entries) {
            foreach ($entries as $id => $fields) {
                $ids[] = $id;
                /** @var array<string, int|float|string|bool|null> $payload */
                $payload = json_decode($fields['payload'] ?? '{}', true) ?: [];
                $events[] = new StreamEvent($id, $fields['type'] ?? 'unknown', (int) ($fields['ts'] ?? 0), $payload);
            }
        }
        if ([] !== $ids) {
            $redis->xAck(EventRecorder::STREAM, self::GROUP, $ids);
        }

        return $events;
    }

    private function setup(\Redis $redis): void
    {
        if ($this->ready) {
            return;
        }
        try {
            $redis->xGroup('CREATE', EventRecorder::STREAM, self::GROUP, '$', true);
        } catch (\RedisException $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
        $redis->clearLastError();
        $this->ready = true;
    }
}
