<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

enum VehicleType: string
{
    case Car = 'car';
    case Motorcycle = 'motorcycle';
    case Bicycle = 'bicycle';
    case Van = 'van';
    case Truck = 'truck';
    case Bus = 'bus';
    case Other = 'other';
}
