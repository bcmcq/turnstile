<?php

declare(strict_types=1);

namespace App\Domain\Venue;

enum SeatType: string
{
    case Standard = 'standard';
    case Premium = 'premium';
    case Ada = 'ada';
}
