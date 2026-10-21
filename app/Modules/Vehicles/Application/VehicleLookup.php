<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;

final class VehicleLookup
{
    public function find(string $tenantId, string $vehicleId, bool $lock = false): Vehicle
    {
        $query = Vehicle::query()->where('tenant_id', $tenantId)->whereKey($vehicleId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
