<?php

declare(strict_types=1);

namespace App\Application\Job\Applier;

use App\Application\Job\JobContext;
use App\Application\Job\TicketChange;
use App\Application\Platform\PlatformApiException;
use App\Domain\Run\RunType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One implementation per RunType. Talks to the platform (if the action needs it) and returns the ticket change to apply. */
#[AutoconfigureTag('app.action_applier')]
interface ActionApplierInterface
{
    public function type(): RunType;

    /** @throws PlatformApiException */
    public function apply(JobContext $ctx): TicketChange;
}
