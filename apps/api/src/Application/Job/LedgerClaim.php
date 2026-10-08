<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Domain\Job\ActionState;

final readonly class LedgerClaim
{
    public function __construct(public int $id, public ActionState $state)
    {
    }
}
