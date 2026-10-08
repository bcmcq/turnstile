<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Run\RunConflictException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/** Uniform JSON errors for the dashboard: {error, status, details?}. */
final class ApiProblem
{
    public function __construct(#[Autowire('%kernel.debug%')] private readonly bool $debug)
    {
    }

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
            $previous instanceof ValidationFailedException => [422, 'validation failed', array_map(static fn (ConstraintViolationInterface $v): string => $v->getPropertyPath() . ': ' . $v->getMessage(), iterator_to_array($previous->getViolations()))],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage(), null],
            // Unhandled exceptions (DBAL ones carry the SQL and its parameters) stay in the log outside debug.
            default => [500, $this->debug ? $e->getMessage() : 'internal error', null],
        };
        $event->setResponse(new JsonResponse(array_filter(['error' => $error, 'status' => $status, 'details' => $details], static fn ($v): bool => null !== $v), $status));
    }
}
