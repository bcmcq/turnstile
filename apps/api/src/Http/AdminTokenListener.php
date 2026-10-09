<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Routes that reach the Docker socket or wipe the arena carry `_admin: true` and need X-Admin-Token.
 * The Origin check in CorsSubscriber only stops browsers; this stops anything that can reach port 8080.
 */
final class AdminTokenListener
{
    public function __construct(private readonly string $adminToken)
    {
    }

    /** Priority below the router (32) so the `_admin` default has been resolved. */
    #[AsEventListener(priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || true !== $request->attributes->get('_admin')) {
            return;
        }
        if (!hash_equals($this->adminToken, (string) $request->headers->get('X-Admin-Token', ''))) {
            throw new AccessDeniedHttpException('X-Admin-Token required');
        }
    }
}
