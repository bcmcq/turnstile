<?php

declare(strict_types=1);

namespace App\Application\Job\Applier;

use App\Application\Job\JobContext;
use App\Application\Job\TicketChange;
use App\Domain\Run\RunType;

/** Adjust price by percent or absolute delta within optional floor/ceiling; push to the platform if listed. */
final class RepriceApplier implements ActionApplierInterface
{
    #[\Override]
    public function type(): RunType
    {
        return RunType::Reprice;
    }

    #[\Override]
    public function apply(JobContext $ctx): TicketChange
    {
        $p = $ctx->run->params;
        $mode = \is_string($p['mode'] ?? null) ? $p['mode'] : 'percent';
        $delta = is_numeric($p['delta'] ?? null) ? (float) $p['delta'] : 0.0;
        $floor = is_numeric($p['floorCents'] ?? null) ? (int) $p['floorCents'] : 100;
        $ceiling = is_numeric($p['ceilingCents'] ?? null) ? (int) $p['ceilingCents'] : \PHP_INT_MAX;

        $current = $ctx->ticket->priceCents;
        $new = 'absolute' === $mode ? $current + (int) round($delta) : (int) round($current * (1 + $delta / 100));
        $new = max($floor, min($ceiling, $new));

        $platform = $ctx->currentPlatform();
        if (null !== $platform && null !== $ctx->ticket->externalRef) {
            $new = max($new, $platform->minPriceCents);
            $result = $ctx->clientFor($platform->code)->reprice($ctx->ticket->externalRef, $new, $ctx->idempotencyKey);
            $new = $result->priceCents;
        }

        return TicketChange::price($new);
    }
}
