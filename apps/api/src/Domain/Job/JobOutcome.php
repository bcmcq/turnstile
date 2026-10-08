<?php

declare(strict_types=1);

namespace App\Domain\Job;

enum JobOutcome: string
{
    case Success = 'success';
    case Http429 = 'http_429';
    case Http5xx = 'http_5xx';
    case Timeout = 'timeout';
    case LockConflict = 'lock_conflict';
    case IdempotentSkip = 'idempotent_skip';
    case SoldDuringRun = 'sold_during_run';
    case Unexpected = 'unexpected';
}
