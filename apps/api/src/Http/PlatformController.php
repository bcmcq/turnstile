<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Platform\ClientRateLimiter;
use App\Application\Platform\PlatformRepository;
use App\Domain\Platform\PlatformCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChaosInput
{
    public function __construct(#[Assert\Range(min: 0, max: 1)] public float $failureRate)
    {
    }
}

final readonly class BuyersInput
{
    public function __construct(#[Assert\Range(min: 0, max: 1)] public float $buyerRate)
    {
    }
}

#[Route('/api/platforms')]
final class PlatformController
{
    public function __construct(private readonly PlatformRepository $platforms, private readonly ClientRateLimiter $limiter)
    {
    }

    #[Route('', name: 'api_platforms', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map(fn ($p): array => ['code' => $p->code, 'name' => $p->code->displayName(), 'color' => $p->code->color(), 'rateLimitPerMin' => $p->rateLimitPerMin, 'feeBps' => $p->feeBps, 'failureRate' => $p->failureRate, 'buyerRate' => $p->buyerRate, 'remainingTokens' => $this->limiter->remaining($p->code)], $this->platforms->all()));
    }

    /** Chaos slider. "all" applies to every platform. */
    #[Route('/{code}/chaos', name: 'api_platforms_chaos', methods: ['PUT'])]
    public function chaos(string $code, #[MapRequestPayload] ChaosInput $input): JsonResponse
    {
        foreach ($this->codes($code) as $c) {
            $this->platforms->setFailureRate($c, $input->failureRate);
        }

        return new JsonResponse(['failureRate' => $input->failureRate, 'platforms' => array_map(static fn (PlatformCode $c): string => $c->value, $this->codes($code))]);
    }

    #[Route('/{code}/buyers', name: 'api_platforms_buyers', methods: ['PUT'])]
    public function buyers(string $code, #[MapRequestPayload] BuyersInput $input): JsonResponse
    {
        foreach ($this->codes($code) as $c) {
            $this->platforms->setBuyerRate($c, $input->buyerRate);
        }

        return new JsonResponse(['buyerRate' => $input->buyerRate, 'platforms' => array_map(static fn (PlatformCode $c): string => $c->value, $this->codes($code))]);
    }

    /** @return list<PlatformCode> */
    private function codes(string $code): array
    {
        return 'all' === $code ? PlatformCode::cases() : [PlatformCode::from($code)];
    }
}
