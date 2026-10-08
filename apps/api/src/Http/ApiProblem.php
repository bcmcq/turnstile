<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Run\RunConflictException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/** Uniform JSON errors for the dashboard: {error, status, details?}. */
final class ApiProblem
{
    #[AsEventListener]
    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $e = $event->getThrowable();
        $previous = $e->getPrevious();
        [$status, $error, $details] = match (true) {
            $e instanceof RunConflictException => [409, $e->getMessage(), null],
            $previous instanceof ValidationFailedException => [422, 'validation failed', array_map(static fn ($v): string => $v->getPropertyPath() . ': ' . $v->getMessage(), iterator_to_array($previous->getViolations()))],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage(), null],
            default => [500, $e->getMessage(), null],
        };
        $event->setResponse(new JsonResponse(array_filter(['error' => $error, 'status' => $status, 'details' => $details], static fn ($v): bool => null !== $v), $status));
    }
}
