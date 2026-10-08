<?php

declare(strict_types=1);

namespace App\Application\Job\Applier;

use App\Application\Job\JobContext;
use App\Application\Job\TicketChange;
use App\Domain\Run\RunType;

/** New barcode from the platform if listed, otherwise a new house barcode. The old one is invalid either way. */
final class RegenerateApplier implements ActionApplierInterface
{
    #[\Override]
    public function type(): RunType
    {
        return RunType::Regenerate;
    }

    #[\Override]
    public function apply(JobContext $ctx): TicketChange
    {
        $platform = $ctx->currentPlatform();
        if (null !== $platform && null !== $ctx->ticket->externalRef) {
            return TicketChange::barcode($ctx->clientFor($platform->code)->regenerate($ctx->ticket->externalRef, $ctx->idempotencyKey));
        }

        return TicketChange::barcode(\sprintf('HS-%s-%06d', strtoupper(bin2hex(random_bytes(2))), $ctx->ticket->id));
    }
}
