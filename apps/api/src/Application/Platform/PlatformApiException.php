<?php

declare(strict_types=1);

namespace App\Application\Platform;

final class PlatformApiException extends \RuntimeException
{
    public function __construct(
        public readonly PlatformFailure $failure,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterMs = null,
        public readonly int $latencyMs = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }
}
