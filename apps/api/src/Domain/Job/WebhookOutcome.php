<?php

declare(strict_types=1);

namespace App\Domain\Job;

enum WebhookOutcome: string
{
    case Applied = 'applied';
    case ConflictRetried = 'conflict_retried';
    case IgnoredAlreadySold = 'ignored_already_sold';
    case Invalid = 'invalid';
}
