<?php

declare(strict_types=1);

use Scaler\Docker;

require __DIR__.'/../src/Docker.php';

// Runs detached from the HTTP request: docker run / stop can take seconds, and nothing upstream should wait on it.
// The lock serialises overlapping requests so a manual step and an autoscale decision cannot race.
$target = (int) ($argv[1] ?? 0);
$lock = fopen('/tmp/scaler.lock', 'c') ?: exit(1);
flock($lock, LOCK_EX);
try {
    $result = Docker::fromEnv()->scale($target);
    fwrite(STDERR, sprintf("scaler: %d -> %d workers (started %s; stopped %s)\n", $result['before'], $result['after'], implode(',', $result['started']) ?: '-', implode(',', $result['stopped']) ?: '-'));
} catch (Throwable $e) {
    fwrite(STDERR, 'scaler: scale failed: '.$e->getMessage()."\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
