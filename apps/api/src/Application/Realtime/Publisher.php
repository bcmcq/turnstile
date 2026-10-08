<?php

declare(strict_types=1);

namespace App\Application\Realtime;

use App\Application\Run\RunRepository;
use App\Application\Run\RunViewFactory;
use App\Application\Scaling\AutoscalePolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * The only process that talks to Mercure. Every tick (250 ms) it drains the worker event stream,
 * folds ticket updates into one seats batch, samples series once a second, and publishes:
 *   seats    only when seats changed
 *   log      the raw events (capped) for the Events tab
 *   run      on lifecycle events
 *   metrics  when something changed, and at least once a second.
 */
final class Publisher
{
    private const int TICK_MS = 250;
    private const int MAX_EVENTS_PER_TICK = 5_000;
    private const int MAX_LOG_PER_TICK = 100;
    private const int SLOW_TICK_MS = 600;
    private const array RUN_EVENTS = ['run.created', 'run.dispatching', 'run.started', 'run.paused', 'run.resumed', 'run.cancelled', 'run.finished', 'demo.reset'];
    /** Per-seat job state codes carried on the seats topic; last one per ticket in a tick wins. Mirrors JobState in the web app. */
    private const array JOB_STATE = ['job.started' => 1, 'job.retry' => 2, 'job.dead_lettered' => 4, 'job.completed' => 5, 'job.skipped' => 6, 'job.requeued' => 7];

    private bool $running = true;

    public function __construct(
        private readonly EventStreamReader $reader,
        private readonly SnapshotBuilder $snapshots,
        private readonly RunRepository $runs,
        private readonly RunViewFactory $runViews,
        private readonly HubInterface $hub,
        private readonly AutoscalePolicy $autoscale,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(?int $maxTicks = null): void
    {
        $series = new SeriesBuffer();
        $completedThisSecond = 0;
        $eventsThisSecond = 0;
        $lastSecond = (int) microtime(true);
        $lastMetricsAt = 0.0;
        $lastAutoscaleAt = 0.0;
        $jobsPerSec = 0.0;
        $eventsPerSec = 0;
        $ticks = 0;

        while ($this->running && (null === $maxTicks || $ticks++ < $maxTicks)) {
            $tickStart = microtime(true);
            $events = $this->reader->read(self::MAX_EVENTS_PER_TICK, self::TICK_MS);
            $eventsThisSecond += \count($events);

            $seats = [];
            $jobs = [];
            $log = [];
            $runChanged = false;
            foreach ($events as $e) {
                $p = $e->payload;
                if ('ticket.updated' === $e->type) {
                    $seats[(int) ($p['ticketId'] ?? 0)] = [(int) ($p['ticketId'] ?? 0), (int) ($p['sectionId'] ?? 0), (int) ($p['state'] ?? 0), (int) ($p['platformId'] ?? 0), (int) ($p['priceCents'] ?? 0)];
                    continue;
                }
                if (isset(self::JOB_STATE[$e->type]) && isset($p['ticketId'])) {
                    $code = self::JOB_STATE[$e->type];
                    if ('job.retry' === $e->type && 'lock_conflict' === ($p['outcome'] ?? null)) {
                        $code = 3;
                    }
                    $jobs[(int) $p['ticketId']] = [(int) $p['ticketId'], $code, (int) ($p['delayMs'] ?? 0)];
                }
                if ('job.completed' === $e->type) {
                    ++$completedThisSecond;
                }
                if (\in_array($e->type, self::RUN_EVENTS, true)) {
                    $runChanged = true;
                }
                // Seat state travels on the seats topic, so the log can be sampled; run and system events always make it.
                if (!str_starts_with($e->type, 'job.') || \count($log) < self::MAX_LOG_PER_TICK) {
                    $log[] = ['type' => $e->type, 'ts' => $e->ts] + $p;
                }
            }

            if ([] !== $seats || [] !== $jobs) {
                $this->publish(Topics::SEATS, ['rows' => array_values($seats), 'jobs' => array_values($jobs)]);
            }
            if ([] !== $log) {
                $this->publish(Topics::LOG, ['events' => $log]);
            }
            if ($runChanged) {
                $run = $this->runs->active() ?? $this->runs->latest();
                $this->publish(Topics::RUN, null === $run ? ['reset' => true] : $this->runViews->make($run));
            }

            $now = microtime(true);
            $second = (int) $now;
            $sampled = false;
            if ($second !== $lastSecond) {
                $jobsPerSec = $completedThisSecond / max(1, $second - $lastSecond);
                $eventsPerSec = (int) ($eventsThisSecond / max(1, $second - $lastSecond));
                $completedThisSecond = 0;
                $eventsThisSecond = 0;
                $lastSecond = $second;
                $sampled = true;
            }

            if ($sampled || [] !== $events || $now - $lastMetricsAt >= 1.0) {
                $snapshot = $this->snapshots->build(round($jobsPerSec, 1), $eventsPerSec, $series->all());
                if ($sampled) {
                    $series->push('jobsPerSec', round($jobsPerSec, 1));
                    $series->push('eventsPerSec', $eventsPerSec);
                    $series->push('queued', $snapshot->counts['queued'] + $snapshot->counts['retry_wait']);
                    $series->push('inFlight', $snapshot->counts['in_flight']);
                    $series->push('failed', $snapshot->counts['dead_lettered']);
                    $snapshot = $this->snapshots->build(round($jobsPerSec, 1), $eventsPerSec, $series->all());
                }
                $this->publish(Topics::METRICS, $snapshot);
                $lastMetricsAt = $now;
            }

            $autoscaleMs = 0;
            if ($now - $lastAutoscaleAt >= 5.0) {
                $lastAutoscaleAt = $now;
                $t = microtime(true);
                try {
                    $this->autoscale->tick();
                } catch (\Throwable $e) {
                    $this->logger->error('autoscale tick failed: {error}', ['error' => $e->getMessage()]);
                }
                $autoscaleMs = (int) ((microtime(true) - $t) * 1000);
            }

            $elapsedMs = (int) ((microtime(true) - $tickStart) * 1000);
            if ($elapsedMs > self::SLOW_TICK_MS) {
                // A slow tick is invisible on the dashboard except as a stutter; say where the time went.
                $this->logger->warning('slow publisher tick: {ms} ms ({events} events, autoscale {autoscale} ms)', ['ms' => $elapsedMs, 'events' => \count($events), 'autoscale' => $autoscaleMs]);
            }
            if ($elapsedMs < self::TICK_MS) {
                usleep((self::TICK_MS - $elapsedMs) * 1000);
            }
        }
    }

    private function publish(string $topic, mixed $data): void
    {
        try {
            // SSE "event:" name = topic suffix, so the browser routes with addEventListener('metrics', …).
            $this->hub->publish(new Update($topic, json_encode($data, \JSON_THROW_ON_ERROR), type: substr($topic, \strlen('turnstile/'))));
        } catch (\Throwable $e) {
            $this->logger->error('mercure publish to {topic} failed: {error}', ['topic' => $topic, 'error' => $e->getMessage()]);
        }
    }
}
