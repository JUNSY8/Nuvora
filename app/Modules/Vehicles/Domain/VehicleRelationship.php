<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

enum VehicleRelationship: string
{
    // At most one active owner per vehicle.
    case Owner = 'owner';
    case Driver = 'driver';
    case Authorized = 'authorized';
}
