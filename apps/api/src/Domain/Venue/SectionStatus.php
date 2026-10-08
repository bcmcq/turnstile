<?php

declare(strict_types=1);

namespace App\Domain\Venue;

enum SectionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
