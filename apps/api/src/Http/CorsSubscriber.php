<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

// 20 lines instead of nelmio/cors-bundle. Single allowed origin, the Vite dev server.
final class CorsSubscriber
{
    private const string ALLOWED_ORIGIN = 'http://localhost:5173';

    #[AsEventListener(priority: 250)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->getRequest()->isMethod('OPTIONS')) {
            $event->setResponse(new Response(status: 204));
        }
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        $event->getResponse()->headers->add([
            'Access-Control-Allow-Origin' => self::ALLOWED_ORIGIN,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Max-Age' => '600',
        ]);
    }
}
