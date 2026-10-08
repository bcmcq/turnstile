<?php

declare(strict_types=1);

namespace App\Application\Platform\Http;

use App\Application\Platform\PlatformApiException;
use App\Application\Platform\PlatformClientInterface;
use App\Application\Platform\PlatformFailure;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** HTTP plumbing shared by the vendor clients: timeout, timing, and mapping responses to PlatformApiException. */
abstract class AbstractHttpPlatformClient implements PlatformClientInterface
{
    protected const float TIMEOUT_SECONDS = 3.0;

    public function __construct(
        protected readonly HttpClientInterface $http,
        protected readonly string $mocksBaseUrl,
    ) {
    }

    /**
     * @param array{json?: array<string, int|string>, body?: array<string, int|string>, headers?: array<string, string>} $options
     *
     * @return array{array<string, mixed>, int} decoded body, latency ms
     *
     * @throws PlatformApiException
     */
    protected function call(string $method, string $path, array $options, string $idempotencyKey, string $idempotencyHeader): array
    {
        $options['headers'] = array_merge($this->defaultHeaders(), $options['headers'] ?? [], [$idempotencyHeader => $idempotencyKey]);
        $options['timeout'] = self::TIMEOUT_SECONDS;          // idle
        $options['max_duration'] = self::TIMEOUT_SECONDS + 1; // hard cap
        $start = hrtime(true);
        $latency = static fn (): int => (int) ((hrtime(true) - $start) / 1e6);

        try {
            $response = $this->http->request($method, $this->mocksBaseUrl . '/' . $this->code()->value . $path, $options);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new PlatformApiException(PlatformFailure::Timeout, $this->code()->displayName() . ': ' . $e->getMessage(), null, null, $latency(), $e);
        }

        $decoded = json_decode($raw, true);
        /** @var array<string, mixed> $body */
        $body = \is_array($decoded) ? $decoded : [];
        $error = \is_string($body['error'] ?? null) ? $body['error'] : 'http ' . $status;

        if ($status >= 200 && $status < 300) {
            if (204 === $status) {
                return [[], $latency()];
            }
            if (!\is_array($decoded)) {
                throw new PlatformApiException(PlatformFailure::ServerError, $this->code()->displayName() . ': empty or malformed ' . $status . ' response', $status, null, $latency());
            }

            return [$body, $latency()];
        }
        throw match (true) {
            429 === $status => new PlatformApiException(PlatformFailure::RateLimited, $this->code()->displayName() . ': rate limited', 429, 1000 * (int) ($response->getHeaders(false)['retry-after'][0] ?? 1), $latency()),
            $status >= 500 => new PlatformApiException(PlatformFailure::ServerError, $this->code()->displayName() . ': ' . $error, $status, null, $latency()),
            409 === $status && 'listing_sold' === $error => new PlatformApiException(PlatformFailure::ListingSold, $this->code()->displayName() . ': listing already sold', 409, null, $latency()),
            default => new PlatformApiException(PlatformFailure::Rejected, $this->code()->displayName() . ': ' . $error, $status, null, $latency()),
        };
    }

    /** @return array<string, string> */
    abstract protected function defaultHeaders(): array;

    /** @param array<string, mixed> $body */
    protected function str(array $body, string $key): string
    {
        $v = $body[$key] ?? null;
        if (\is_string($v) || \is_int($v)) {
            return (string) $v;
        }
        throw new PlatformApiException(PlatformFailure::Rejected, $this->code()->displayName() . ": response missing \"{$key}\"");
    }

    /** @param array<string, mixed> $body */
    protected function int(array $body, string $key): int
    {
        $v = $body[$key] ?? null;
        if (is_numeric($v)) {
            return (int) $v;
        }
        throw new PlatformApiException(PlatformFailure::Rejected, $this->code()->displayName() . ": response missing \"{$key}\"");
    }
}
