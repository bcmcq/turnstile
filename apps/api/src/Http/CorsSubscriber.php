<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// A few lines instead of nelmio/cors-bundle. Allowed origins are the Vite dev server; must match the Caddyfile's cors_origins.
final class CorsSubscriber
{
    private const array ALLOWED_ORIGINS = ['http://localhost:5173', 'http://127.0.0.1:5173'];
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    #[AsEventListener(priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->isMethod('OPTIONS')) {
            $event->setResponse(new Response(status: 204));

            return;
        }
        // CORS only hides the response; a cross-site form POST still executes. Browsers always send Origin on
        // those, so an unknown Origin on a mutating request is refused. Server-to-server calls carry no Origin.
        $origin = $request->headers->get('Origin');
        if (null !== $origin && !\in_array($request->getMethod(), self::SAFE_METHODS, true) && !\in_array($origin, self::ALLOWED_ORIGINS, true)) {
            throw new AccessDeniedHttpException('origin not allowed');
        }
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        $origin = $event->getRequest()->headers->get('Origin', '');
        $event->getResponse()->headers->add([
            'Access-Control-Allow-Origin' => \in_array($origin, self::ALLOWED_ORIGINS, true) ? $origin : self::ALLOWED_ORIGINS[0],
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ]);
    }
}
