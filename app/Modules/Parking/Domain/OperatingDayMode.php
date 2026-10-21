<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

enum OperatingDayMode: string
{
    case Closed = 'closed';
    case Open24h = 'open_24h';
    case Scheduled = 'scheduled';
}
