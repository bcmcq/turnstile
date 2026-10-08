<?php

declare(strict_types=1);

namespace App\Application\Job\Applier;

use App\Application\Job\JobContext;
use App\Application\Job\TicketChange;
use App\Domain\Run\RunType;
use App\Domain\Ticket\TicketStatus;

/** Closed → available. No platform call. */
final class OpenSectionApplier implements ActionApplierInterface
{
    #[\Override]
    public function type(): RunType
    {
        return RunType::OpenSection;
    }

    #[\Override]
    public function apply(JobContext $ctx): TicketChange
    {
        return TicketChange::status(TicketStatus::Available);
    }
}
