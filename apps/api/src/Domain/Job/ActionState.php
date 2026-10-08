<?php

declare(strict_types=1);

namespace App\Domain\Job;

enum ActionState: string
{
    case Pending = 'pending';
    case Applied = 'applied';
}
