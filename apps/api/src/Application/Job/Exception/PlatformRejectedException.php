<?php

declare(strict_types=1);

namespace App\Application\Job\Exception;

use App\Application\Platform\PlatformApiException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** A 4xx the platform will keep returning. Straight to the dead letter, no retries. */
final class PlatformRejectedException extends UnrecoverableMessageHandlingException
{
    public function __construct(public readonly PlatformApiException $cause)
    {
        parent::__construct($cause->getMessage(), previous: $cause);
    }
}
