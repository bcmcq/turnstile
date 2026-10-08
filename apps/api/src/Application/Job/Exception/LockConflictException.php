<?php

declare(strict_types=1);

namespace App\Application\Job\Exception;

/** Another writer changed the ticket row mid-job. Retry soon with jitter; the retry re-reads the ticket. */
final class LockConflictException extends \RuntimeException implements RetryDelayAwareException
{
    private readonly int $retryDelayMs;

    public function __construct(int $ticketId, int $expectedVersion)
    {
        parent::__construct(\sprintf('version conflict on ticket %d (expected v%d)', $ticketId, $expectedVersion));
        $this->retryDelayMs = random_int(200, 600);
    }

    #[\Override]
    public function retryDelayMs(): int
    {
        return $this->retryDelayMs;
    }
}
