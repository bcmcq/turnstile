<?php

declare(strict_types=1);

namespace App\Application\Job\Applier;

use App\Application\Job\JobContext;
use App\Application\Job\TicketChange;
use App\Domain\Run\RunType;
use App\Domain\Ticket\TicketStatus;

/** Delist if listed, then withhold the ticket. The section's own status flips when the run finishes. */
final class CloseSectionApplier implements ActionApplierInterface
{
    #[\Override]
    public function type(): RunType
    {
        return RunType::CloseSection;
    }

    #[\Override]
    public function apply(JobContext $ctx): TicketChange
    {
        $current = $ctx->currentPlatform();
        if (null !== $current && null !== $ctx->ticket->externalRef) {
            $ctx->clientFor($current->code)->delist($ctx->ticket->externalRef, $ctx->idempotencyKey);
        }

        return TicketChange::unlisted(TicketStatus::Closed, $ctx->ticket->faceValueCents);
    }
}
