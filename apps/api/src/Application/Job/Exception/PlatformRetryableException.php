<?php

declare(strict_types=1);

namespace App\Application\Job\Exception;

use App\Application\Platform\PlatformApiException;

/** 429 / 5xx / timeout. Carries the platform's Retry-After so the retry strategy honors it. */
final class PlatformRetryableException extends \RuntimeException implements RetryDelayAwareException
{
    public function __construct(public readonly PlatformApiException $cause)
    {
        parent::__construct($cause->getMessage(), previous: $cause);
    }

    #[\Override]
    public function retryDelayMs(): ?int
    {
        return $this->cause->retryAfterMs;
    }
}
