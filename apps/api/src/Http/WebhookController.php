<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Webhook\ListingSoldHandler;
use App\Domain\Platform\PlatformCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class WebhookController
{
    /** Inbound marketplace events, HMAC-signed. Replays of the same delivery_id are acknowledged without re-applying. */
    #[Route('/api/webhooks/{platform}', name: 'api_webhooks', methods: ['POST'])]
    public function __invoke(string $platform, Request $request, ListingSoldHandler $handler): JsonResponse
    {
        $code = PlatformCode::tryFrom($platform) ?? throw new BadRequestHttpException('unknown platform');
        $raw = $request->getContent();
        if (!$handler->verify($code, $raw, (string) $request->headers->get('X-Signature', ''))) {
            throw new UnauthorizedHttpException('HMAC', 'bad signature');
        }
        $payload = json_decode($raw, true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('body must be a JSON object');
        }
        /** @var array<string, mixed> $payload */
        $deliveryId = \is_string($payload['delivery_id'] ?? null) ? $payload['delivery_id'] : '';
        if ('' !== $deliveryId && $handler->isDuplicate($code, $deliveryId)) {
            return new JsonResponse(['outcome' => 'duplicate']);
        }

        return new JsonResponse(['outcome' => $handler->handle($code, $payload)->value]);
    }
}
