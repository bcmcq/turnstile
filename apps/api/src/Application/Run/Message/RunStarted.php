<?php

declare(strict_types=1);

namespace App\Application\Run\Message;

/** Control-plane message: fan the run out into one ProcessTicketJob per ticket. */
final readonly class RunStarted
{
    public function __construct(public string $runId)
    {
    }
}
