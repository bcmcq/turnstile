<?php

declare(strict_types=1);

namespace App\Application\Job\Applier;

use App\Application\Job\JobContext;
use App\Application\Job\TicketChange;
use App\Application\Platform\Dto\ListingRequest;
use App\Domain\Run\RunType;

/** Delist from the current platform (if any), list on the target at face value plus the target's fee. */
final class TransferApplier implements ActionApplierInterface
{
    #[\Override]
    public function type(): RunType
    {
        return RunType::Transfer;
    }

    #[\Override]
    public function apply(JobContext $ctx): TicketChange
    {
        $target = $ctx->targetPlatform();
        $current = $ctx->currentPlatform();
        $ticket = $ctx->ticket;

        if (null !== $current && null !== $ticket->externalRef && $current->id !== $target->id) {
            $ctx->clientFor($current->code)->delist($ticket->externalRef, $ctx->idempotencyKey . ':delist');
        }
        if (null !== $current && $current->id === $target->id && null !== $ticket->externalRef) {
            return TicketChange::listed($target->id, $ticket->externalRef, $ticket->priceCents); // already there
        }
        $result = $ctx->clientFor($target->code)->list(new ListingRequest($ticket->id, $target->listingPriceFor($ticket->faceValueCents), $ticket->barcode), $ctx->idempotencyKey);

        return TicketChange::listed($target->id, $result->externalRef, $result->priceCents);
    }
}
