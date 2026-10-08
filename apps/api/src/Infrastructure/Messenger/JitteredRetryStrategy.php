<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Application\Job\Exception\RetryDelayAwareException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * 1s · 2s · 4s · 8s with ±25% jitter for 5 attempts in total. An exception that carries its own delay
 * (a platform's Retry-After, or a lock conflict) overrides the schedule. Only Unrecoverable exceptions skip
 * retrying; handlers must never throw Messenger's RecoverableExceptionInterface, which retries forever.
 */
final class JitteredRetryStrategy implements RetryStrategyInterface
{
    public function __construct(
        private readonly int $maxRetries = 4,
        private readonly int $baseDelayMs = 1_000,
        private readonly int $maxDelayMs = 8_000,
        private readonly float $jitter = 0.25,
    ) {
    }

    #[\Override]
    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        foreach (self::unwrap($throwable) as $e) {
            if ($e instanceof UnrecoverableExceptionInterface) {
                return false;
            }
        }

        return RedeliveryStamp::getRetryCountFromEnvelope($message) < $this->maxRetries;
    }

    #[\Override]
    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        foreach (self::unwrap($throwable) as $e) {
            if ($e instanceof RetryDelayAwareException && null !== $e->retryDelayMs()) {
                return $e->retryDelayMs();
            }
        }
        $retries = RedeliveryStamp::getRetryCountFromEnvelope($message);
        $delay = min($this->maxDelayMs, $this->baseDelayMs * (2 ** $retries));
        $spread = (int) ($delay * $this->jitter);

        return $delay + random_int(-$spread, $spread);
    }

    /** @return list<\Throwable> */
    private static function unwrap(?\Throwable $throwable): array
    {
        if (null === $throwable) {
            return [];
        }

        return $throwable instanceof HandlerFailedException ? array_values($throwable->getWrappedExceptions()) : [$throwable];
    }
}
