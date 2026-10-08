<?php

declare(strict_types=1);

namespace App\Application\Job\Message;

use App\Domain\Platform\PlatformCode;

/** One unit of work. Routed to the platform's own transport with a TransportNamesStamp at dispatch time. */
final readonly class ProcessTicketJob
{
    public function __construct(
        public int $jobId,
        public string $runId,
        public int $ticketId,
        public ?PlatformCode $platform,
    ) {
    }

    public function transport(): string
    {
        return $this->platform?->transport() ?? 'platform_house';
    }
}
