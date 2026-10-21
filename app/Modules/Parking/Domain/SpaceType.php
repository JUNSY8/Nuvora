<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

enum SpaceType: string
{
    case Car = 'car';
    case Motorcycle = 'motorcycle';
    case Truck = 'truck';
    case Van = 'van';
    case Ev = 'ev';
    case Disabled = 'disabled';
    case Vip = 'vip';
    case Reserved = 'reserved';
}
