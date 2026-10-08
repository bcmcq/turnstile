<?php

declare(strict_types=1);

namespace App\Application\Job\Exception;

/**
 * A failure that should be retried, with an optional delay override. Deliberately NOT Messenger's
 * RecoverableExceptionInterface: Messenger retries those unconditionally, bypassing the retry strategy's cap.
 */
interface RetryDelayAwareException extends \Throwable
{
    public function retryDelayMs(): ?int;
}
