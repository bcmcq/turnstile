<?php

declare(strict_types=1);

namespace App\Domain\Event;

enum EventStatus: string
{
    case Scheduled = 'scheduled';
    case OnSale = 'on_sale';
    case Closed = 'closed';
}
