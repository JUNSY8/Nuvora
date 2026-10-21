<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use Illuminate\Validation\ValidationException;

/**
 * Checks, through the Parking contract, that the lot and gate chosen for a
 * device belong to the active tenant.
 */
final class DevicePlacement
{
    public function __construct(private readonly ParkingInfrastructureDirectory $parking) {}

    public function assertValid(string $tenantId, string $lotId, ?string $gateId): void
    {
        if (! $this->parking->lotExists($tenantId, $lotId)) {
            throw ValidationException::withMessages([
                'parking_lot_id' => 'Selecciona un parqueadero de esta empresa.',
            ]);
        }

        if ($gateId !== null && ! $this->parking->gateBelongsToLot($tenantId, $lotId, $gateId)) {
            throw ValidationException::withMessages([
                'gate_id' => 'La puerta no pertenece al parqueadero seleccionado.',
            ]);
        }
    }
}
