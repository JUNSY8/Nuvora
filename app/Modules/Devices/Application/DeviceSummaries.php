<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Devices\Infrastructure\Persistence\Device;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;

/**
 * Resolves the lot and gate labels shown next to each device in one query
 * per resource type.
 */
final class DeviceSummaries
{
    public function __construct(private readonly ParkingInfrastructureDirectory $parking) {}

    /**
     * @param  iterable<Device>  $devices
     * @return array{lots: array<string, array{id: string, name: string, code: string}>, gates: array<string, array{id: string, name: string, code: string}>}
     */
    public function for(string $tenantId, iterable $devices): array
    {
        $lotIds = [];
        $gateIds = [];

        foreach ($devices as $device) {
            $lotIds[(string) $device->parking_lot_id] = true;

            if ($device->gate_id !== null) {
                $gateIds[(string) $device->gate_id] = true;
            }
        }

        return [
            'lots' => $this->parking->lotSummaries($tenantId, array_keys($lotIds)),
            'gates' => $this->parking->gateSummaries($tenantId, array_keys($gateIds)),
        ];
    }
}
