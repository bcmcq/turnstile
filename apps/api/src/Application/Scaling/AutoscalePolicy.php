<?php

declare(strict_types=1);

namespace App\Application\Scaling;

use App\Application\Realtime\EventRecorder;
use App\Application\Realtime\WorkerRegistry;
use App\Infrastructure\Redis\RedisFactory;
use Psr\Log\LoggerInterface;

/**
 * Queue-depth autoscaler, ticked by the publisher every few seconds when enabled.
 *   depth / workers > SCALE_UP_PER_WORKER      → +SCALE_UP_STEP workers (max MAX)
 *   depth < SCALE_DOWN_DEPTH and per-worker < 25 → −SCALE_DOWN_STEP workers (min MIN)
 * Scale-up waits COOLDOWN_SECONDS for the new workers to show up; scale-down runs every tick so an idle
 * fleet drains to MIN quickly. Production would hand this to KEDA / an HPA on the same signal.
 */
final class AutoscalePolicy
{
    public const int MIN = 2;
    public const int MAX = 24;
    private const int SCALE_UP_PER_WORKER = 25;
    private const int SCALE_UP_STEP = 4;
    private const int SCALE_DOWN_DEPTH = 200;
    private const int SCALE_DOWN_PER_WORKER = 25;
    private const int SCALE_DOWN_STEP = 2;
    private const int COOLDOWN_SECONDS = 5;
    private const array STREAMS = ['turnstile_platform_tixhub', 'turnstile_platform_seatswap', 'turnstile_platform_passmarket', 'turnstile_platform_house'];

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly WorkerRegistry $workers,
        private readonly ScalerClientInterface $scaler,
        private readonly EventRecorder $events,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return '1' === $this->redis->get()->get('autoscale:enabled');
    }

    public function setEnabled(bool $enabled): void
    {
        $redis = $this->redis->get();
        $redis->set('autoscale:enabled', $enabled ? '1' : '0');
        $redis->set('autoscale:last_decision', $enabled ? 'enabled · watching queue depth' : 'disabled');
    }

    public function queueDepth(): int
    {
        $redis = $this->redis->get();
        $depth = 0;
        foreach (self::STREAMS as $stream) {
            $len = $redis->rawCommand('XLEN', $stream);
            $depth += is_numeric($len) ? (int) $len : 0;
        }

        return $depth;
    }

    /** @return string|null the decision taken, if any */
    public function tick(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $redis = $this->redis->get();
        $lastRaw = $redis->get('autoscale:last_change_at');
        $last = is_numeric($lastRaw) ? (int) $lastRaw : 0;
        $coolingDown = time() - $last < self::COOLDOWN_SECONDS;
        $workers = max(1, \count($this->workers->all()));
        $depth = $this->queueDepth();
        $perWorker = intdiv($depth, $workers);

        $target = null;
        $reason = '';
        if ($perWorker > self::SCALE_UP_PER_WORKER && $workers < self::MAX) {
            if ($coolingDown) {
                return null;
            }
            $target = min(self::MAX, $workers + self::SCALE_UP_STEP);
            $reason = \sprintf('%d queued ÷ %d workers = %d each > %d', $depth, $workers, $perWorker, self::SCALE_UP_PER_WORKER);
        } elseif ($depth < self::SCALE_DOWN_DEPTH && $perWorker < self::SCALE_DOWN_PER_WORKER && $workers > self::MIN) {
            $target = max(self::MIN, $workers - self::SCALE_DOWN_STEP);
            $reason = \sprintf('%d queued, %d each < %d', $depth, $perWorker, self::SCALE_DOWN_PER_WORKER);
        }
        if (null === $target) {
            return null;
        }

        try {
            $this->scaler->scale($target);
        } catch (ScalerException $e) {
            $this->logger->error('autoscale failed: {error}', ['error' => $e->getMessage()]);
            $redis->set('autoscale:last_decision', 'scaler error: ' . $e->getMessage());

            return null;
        }
        $decision = \sprintf('%d → %d · %s', $workers, $target, $reason);
        $redis->set('autoscale:last_change_at', (string) time());
        $redis->set('autoscale:last_decision', $decision);
        $this->events->push('autoscale.decision', ['from' => $workers, 'to' => $target, 'depth' => $depth, 'reason' => $reason]);

        return $decision;
    }
}
