<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

enum GateStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Maintenance = 'maintenance';
}
