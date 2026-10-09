<?php

declare(strict_types=1);

use Scaler\Docker;

require __DIR__.'/../src/Docker.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

/** @param array<string, mixed> $body */
$respond = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

try {
    $docker = Docker::fromEnv();
    if ('GET' === $method && '' === $path) {
        $respond(200, ['service' => 'scaler', 'workers' => $docker->workers()]);
    }
    if ('POST' === $method && '/scale' === $path) {
        $in = json_decode(file_get_contents('php://input') ?: '', true);
        $target = \is_array($in) && is_numeric($in['workers'] ?? null) ? (int) $in['workers'] : null;
        if (null === $target) {
            $respond(422, ['error' => 'body must be {"workers": n}']);
        }
        // Accept and return at once; bin/scale.php does the docker work in the background (logs go to the container's stderr).
        // The target file is replaced atomically, so overlapping requests collapse to the newest value.
        $target = max(1, min($docker->max, $target));
        $running = \count(array_filter($docker->workers(), static fn (array $w): bool => 'running' === $w['state']));
        file_put_contents('/tmp/scaler.target.tmp', (string) $target);
        rename('/tmp/scaler.target.tmp', '/tmp/scaler.target');
        exec(sprintf('php %s >> /proc/1/fd/2 2>&1 &', escapeshellarg(__DIR__.'/../bin/scale.php')));
        $respond(202, ['target' => $target, 'before' => $running, 'accepted' => true, 'workers' => $docker->workers()]);
    }
    $respond(404, ['error' => 'no_route']);
} catch (Throwable $e) {
    $respond(500, ['error' => $e->getMessage()]);
}
