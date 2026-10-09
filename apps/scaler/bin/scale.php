<?php

declare(strict_types=1);

use Scaler\Docker;

require __DIR__.'/../src/Docker.php';

// Runs detached from the HTTP request: docker run / stop can take seconds, and nothing upstream should wait on it.
// index.php writes the wanted count to TARGET_FILE and spawns one of these per request; the lock serialises them and
// each one scales to whatever the file says *now*, so a burst of stepper clicks collapses to the last target.
const TARGET_FILE = '/tmp/scaler.target';

$lock = fopen('/tmp/scaler.lock', 'c') ?: exit(1);
flock($lock, LOCK_EX);
try {
    $raw = @file_get_contents(TARGET_FILE);
    if (false === $raw) {
        exit(0); // an earlier runner already applied this target
    }
    unlink(TARGET_FILE);
    $result = Docker::fromEnv()->scale((int) $raw);
    fwrite(STDERR, sprintf("scaler: %d -> %d workers (started %s; stopped %s)\n", $result['before'], $result['after'], implode(',', $result['started']) ?: '-', implode(',', $result['stopped']) ?: '-'));
} catch (Throwable $e) {
    fwrite(STDERR, 'scaler: scale failed: '.$e->getMessage()."\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
}
