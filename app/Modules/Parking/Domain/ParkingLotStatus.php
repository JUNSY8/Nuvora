<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

enum ParkingLotStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Maintenance = 'maintenance';
}
