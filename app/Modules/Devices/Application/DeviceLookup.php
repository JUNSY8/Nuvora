<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Devices\Infrastructure\Persistence\Device;

final class DeviceLookup
{
    public function find(string $tenantId, string $deviceId, bool $lock = false): Device
    {
        $query = Device::query()->where('tenant_id', $tenantId)->whereKey($deviceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
