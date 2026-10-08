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
        $respond(200, $docker->scale($target));
    }
    $respond(404, ['error' => 'no_route']);
} catch (Throwable $e) {
    $respond(500, ['error' => $e->getMessage()]);
}
