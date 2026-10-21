<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

enum VehicleStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    // Denied at the gate once access control arrives; always needs a reason.
    case Blocked = 'blocked';

    public function requiresReason(): bool
    {
        return $this === self::Blocked;
    }
}
