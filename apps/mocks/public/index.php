<?php

declare(strict_types=1);

use Mocks\Marketplace;

require __DIR__.'/../src/Marketplace.php';

// A stray notice in the body would corrupt the JSON a client is parsing; log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// --- bootstrap -------------------------------------------------------------------------------------------
$redisUrl = getenv('REDIS_URL') ?: 'redis://redis:6379';
$parts = parse_url($redisUrl);
$redis = new Redis();
$redis->connect(\is_array($parts) ? ($parts['host'] ?? 'redis') : 'redis', \is_array($parts) ? ($parts['port'] ?? 6379) : 6379, 2.0);
$redis->setOption(Redis::OPT_READ_TIMEOUT, 2.0);
$requestStart = hrtime(true);
register_shutdown_function(static function () use ($requestStart): void {
    $ms = (hrtime(true) - $requestStart) / 1e6;
    if ($ms > 2_000) {
        error_log(sprintf('[mocks] slow request %s %s took %.0f ms', $_SERVER['REQUEST_METHOD'] ?? '?', $_SERVER['REQUEST_URI'] ?? '?', $ms));
    }
});
$webhookUrl = getenv('API_WEBHOOK_URL') ?: 'http://api:8080/api/webhooks';

$markets = [
    'tixhub' => new Marketplace('tixhub', $redis, $webhookUrl, ['min' => 120, 'max' => 250]),
    'seatswap' => new Marketplace('seatswap', $redis, $webhookUrl, ['min' => 150, 'max' => 350]),
    'passmarket' => new Marketplace('passmarket', $redis, $webhookUrl, ['min' => 300, 'max' => 900]),
];

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s));

/** @param array<string, mixed> $body @param array<string, string> $headers */
$respond = static function (int $status, array $body, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $k => $v) {
        header("{$k}: {$v}");
    }
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
};

/** @return array<string, mixed> */
$jsonBody = static function (): array {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);

    return \is_array($decoded) ? $decoded : [];
};

$header = static fn (string $name): string => (string) ($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] ?? '');

// --- root + admin ----------------------------------------------------------------------------------------
if ([] === $segments) {
    $respond(200, ['service' => 'mocks', 'platforms' => array_keys($markets), 'status' => 'ok']);
}

if ('admin' === $segments[0]) {
    $market = $markets[$segments[1] ?? ''] ?? null;
    if (null === $market) {
        $respond(404, ['error' => 'unknown_platform']);
    }
    $what = $segments[2] ?? '';
    if ('GET' === $method && 'stats' === $what) {
        $respond(200, $market->stats());
    }
    if ('PUT' === $method && \in_array($what, ['chaos', 'buyers', 'rate-limit'], true)) {
        $in = $jsonBody();
        $value = match ($what) {
            'chaos' => (string) (float) ($in['failure_rate'] ?? 0),
            'buyers' => (string) (float) ($in['buyer_rate'] ?? 0),
            default => (string) (int) ($in['per_min'] ?? 3000),
        };
        $market->setShared('rate-limit' === $what ? 'ratelimit' : $what, $value);
        $respond(200, ['platform' => $market->code, $what => $value]);
    }
    $respond(405, ['error' => 'method_not_allowed']);
}

// --- platform routes -------------------------------------------------------------------------------------
$market = $markets[$segments[0]] ?? null;
if (null === $market) {
    $respond(404, ['error' => 'unknown_platform']);
}
$rest = \array_slice($segments, 1);

if ('GET' === $method && ['health'] === $rest) {
    $respond(200, ['platform' => $market->code, 'status' => 'ok']);
}
if ('GET' === $method && 2 === \count($rest) && 'listings' === $rest[0]) {
    $listing = $market->listing($rest[1]);
    $respond(null === $listing ? 404 : 200, $listing ?? ['error' => 'not_found']);
}

// Each vendor has its own shape: auth header, idempotency header, resource name, field names, encoding.
switch ($market->code) {
    case 'tixhub':
        if ('Bearer tixhub-demo-token' !== $header('Authorization')) {
            $respond(401, ['error' => 'unauthorized']);
        }
        $idem = $header('Idempotency-Key');
        $in = $jsonBody();
        $id = $rest[1] ?? '';
        $route = $method.' '.implode('/', array_map(static fn (string $s): string => $s === $id && '' !== $id ? '{id}' : $s, $rest));
        [$op, $exec] = match ($route) {
            'POST listings' => ['create', static fn (): array => $market->createListing((int) ($in['ticket_id'] ?? 0), (int) ($in['price_cents'] ?? 0), (string) ($in['barcode'] ?? ''))],
            'DELETE listings/{id}' => ['delist', static fn (): array => $market->delist($id)],
            'PATCH listings/{id}/price' => ['reprice', static fn (): array => $market->reprice($id, (int) ($in['price_cents'] ?? 0))],
            'POST listings/{id}/regenerate' => ['regenerate', static fn (): array => $market->regenerate($id)],
            default => [null, null],
        };
        break;

    case 'seatswap':
        if ('seatswap-demo-key' !== $header('X-Api-Key')) {
            $respond(401, ['error' => 'unauthorized']);
        }
        $idem = $header('X-Idempotency-Key');
        $in = $jsonBody();
        $id = $rest[1] ?? '';
        $route = $method.' '.implode('/', array_map(static fn (string $s): string => $s === $id && '' !== $id ? '{id}' : $s, $rest));
        $dollars = static fn (mixed $v): int => (int) round(((float) $v) * 100);
        [$op, $exec] = match ($route) {
            'POST inventory' => ['create', static function () use ($market, $in, $dollars): array {
                [$s, $b, $lid] = $market->createListing((int) ($in['externalId'] ?? 0), $dollars($in['askingPrice'] ?? 0), (string) ($in['barcode'] ?? ''));

                return [$s, ['inventoryId' => $b['id'], 'state' => 'LIVE', 'askingPrice' => number_format(((int) $b['price_cents']) / 100, 2, '.', '')], $lid];
            }],
            'DELETE inventory/{id}' => ['delist', static function () use ($market, $id): array {
                [$s, $b, $lid] = $market->delist($id);

                return [200 === $s ? 204 : $s, $b, $lid];
            }],
            'PUT inventory/{id}' => ['reprice', static function () use ($market, $id, $in, $dollars): array {
                [$s, $b, $lid] = $market->reprice($id, $dollars($in['askingPrice'] ?? 0));

                return [$s, 200 === $s ? ['inventoryId' => $id, 'askingPrice' => number_format(((int) $b['price_cents']) / 100, 2, '.', '')] : $b, $lid];
            }],
            'POST inventory/{id}/reissue' => ['regenerate', static fn (): array => $market->regenerate($id)],
            default => [null, null],
        };
        break;

    case 'passmarket':
        if ('Basic '.base64_encode('turnstile:passmarket-demo') !== $header('Authorization')) {
            $respond(401, ['error' => 'unauthorized']);
        }
        $idem = $header('Idempotency-Key');
        $in = $_POST; // form-encoded
        $id = $rest[2] ?? '';
        $route = $method.' '.implode('/', array_map(static fn (string $s): string => $s === $id && '' !== $id ? '{id}' : $s, $rest));
        $wrap = static fn (array $r): array => [$r[0], ['offer' => $r[1]], $r[2]];
        [$op, $exec] = match ($route) {
            'POST v2/offers' => ['create', static fn (): array => $wrap($market->createListing((int) ($in['ticket'] ?? 0), (int) ($in['price_cents'] ?? 0), (string) ($in['barcode'] ?? '')))],
            'POST v2/offers/{id}/withdraw' => ['delist', static fn (): array => $wrap($market->delist($id))],
            'POST v2/offers/{id}/price' => ['reprice', static fn (): array => $wrap($market->reprice($id, (int) ($in['price_cents'] ?? 0)))],
            'POST v2/offers/{id}/barcode' => ['regenerate', static fn (): array => $wrap($market->regenerate($id))],
            default => [null, null],
        };
        break;

    default:
        $op = null;
        $exec = null;
        $idem = '';
}

if (null === $op || null === $exec) {
    $respond(404, ['error' => 'no_route', 'method' => $method, 'path' => $path]);
}

[$status, $body, $headers] = $market->handle($op, $idem, $exec);
$respond($status, $body, $headers);
