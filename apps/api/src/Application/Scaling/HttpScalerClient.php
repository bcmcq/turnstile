<?php

declare(strict_types=1);

namespace App\Application\Scaling;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpScalerClient implements ScalerClientInterface
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $scalerUrl)
    {
    }

    #[\Override]
    public function scale(int $workers): ScaleResult
    {
        try {
            $r = $this->http->request('POST', $this->scalerUrl . '/scale', ['json' => ['workers' => $workers], 'timeout' => 10, 'max_duration' => 60]);
            /** @var array{target?: int, before?: int, workers?: list<array{name: string, state: string}>, error?: string} $body */
            $body = $r->toArray(false);
            if ($r->getStatusCode() >= 400) {
                throw new ScalerException($body['error'] ?? 'scaler error');
            }
        } catch (ExceptionInterface $e) {
            throw new ScalerException('scaler unreachable: ' . $e->getMessage(), 0, $e);
        }

        return new ScaleResult((int) ($body['target'] ?? $workers), (int) ($body['before'] ?? 0), self::names($body['workers'] ?? []));
    }

    #[\Override]
    public function running(): array
    {
        try {
            /** @var array{workers?: list<array{name: string, state: string}>} $body */
            $body = $this->http->request('GET', $this->scalerUrl . '/', ['timeout' => 5])->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new ScalerException('scaler unreachable: ' . $e->getMessage(), 0, $e);
        }

        return self::names($body['workers'] ?? []);
    }

    /**
     * @param list<array{name: string, state: string}> $workers
     *
     * @return list<string>
     */
    private static function names(array $workers): array
    {
        return array_values(array_map(static fn (array $w): string => $w['name'], array_filter($workers, static fn (array $w): bool => 'running' === $w['state'])));
    }
}
