<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Platform\ClientRateLimiter;
use App\Application\Platform\Dto\BuyersInput;
use App\Application\Platform\Dto\ChaosInput;
use App\Application\Platform\Dto\PacingInput;
use App\Application\Platform\Dto\RateLimitInput;
use App\Application\Platform\PlatformRepository;
use App\Application\Platform\PlatformRow;
use App\Domain\Platform\PlatformCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/platforms')]
final class PlatformController
{
    public function __construct(private readonly PlatformRepository $platforms, private readonly ClientRateLimiter $limiter)
    {
    }

    #[Route('', name: 'api_platforms', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(array_map(fn (PlatformRow $p): array => ['code' => $p->code, 'name' => $p->code->displayName(), 'color' => $p->code->color(), 'rateLimitPerMin' => $p->rateLimitPerMin, 'feeBps' => $p->feeBps, 'failureRate' => $p->failureRate, 'buyerRate' => $p->buyerRate, 'remainingTokens' => $this->limiter->remaining($p->code)], $this->platforms->all()));
    }

    /** Chaos slider. "all" applies to every platform. */
    #[Route('/{code}/chaos', name: 'api_platforms_chaos', methods: ['PUT'])]
    public function chaos(string $code, #[MapRequestPayload(acceptFormat: 'json')] ChaosInput $input): JsonResponse
    {
        foreach ($this->codes($code) as $c) {
            $this->platforms->setFailureRate($c, $input->failureRate);
        }

        return new JsonResponse(['failureRate' => $input->failureRate, 'platforms' => array_map(static fn (PlatformCode $c): string => $c->value, $this->codes($code))]);
    }

    #[Route('/{code}/buyers', name: 'api_platforms_buyers', methods: ['PUT'])]
    public function buyers(string $code, #[MapRequestPayload(acceptFormat: 'json')] BuyersInput $input): JsonResponse
    {
        foreach ($this->codes($code) as $c) {
            $this->platforms->setBuyerRate($c, $input->buyerRate);
        }

        return new JsonResponse(['buyerRate' => $input->buyerRate, 'platforms' => array_map(static fn (PlatformCode $c): string => $c->value, $this->codes($code))]);
    }

    /** Vendor limit slider. The mocks enforce the new limit at once; client pacing follows it unless pacing is pinned. */
    #[Route('/{code}/rate-limit', name: 'api_platforms_rate_limit', methods: ['PUT'])]
    public function rateLimit(string $code, #[MapRequestPayload(acceptFormat: 'json')] RateLimitInput $input): JsonResponse
    {
        foreach ($this->codes($code) as $c) {
            $this->platforms->setRateLimit($c, $input->rpm);
            $this->limiter->drain($c);
        }

        return new JsonResponse(['rpm' => $input->rpm, 'platforms' => array_map(static fn (PlatformCode $c): string => $c->value, $this->codes($code))]);
    }

    /** Whether workers pace to the vendor limit (no 429s) or stay at the default and let 429s drive backoff. */
    #[Route('/pacing', name: 'api_platforms_pacing', methods: ['PUT'])]
    public function pacing(#[MapRequestPayload(acceptFormat: 'json')] PacingInput $input): JsonResponse
    {
        $this->limiter->setFollowsVendorLimit($input->follow);

        return new JsonResponse(['follow' => $input->follow]);
    }

    /** @return list<PlatformCode> */
    private function codes(string $code): array
    {
        return 'all' === $code ? PlatformCode::cases() : [PlatformCode::tryFrom($code) ?? throw new NotFoundHttpException('unknown platform')];
    }
}
