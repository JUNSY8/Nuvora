<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use Illuminate\Validation\ValidationException;

/**
 * Validates the gate an operator records for an entry or exit. A gate is
 * optional, but when given it must be an active gate of the lot that
 * serves that direction.
 */
final class GateCheck
{
    public function __construct(private readonly ParkingInfrastructureDirectory $parking) {}

    public function assertUsable(string $tenantId, string $lotId, ?string $gateId, GateDirection $direction): void
    {
        if ($gateId === null) {
            return;
        }

        $gate = $this->parking->gate($tenantId, $lotId, $gateId);

        if ($gate === null) {
            throw ValidationException::withMessages(['gate_id' => 'La puerta no pertenece a este parqueadero.']);
        }

        if ($gate['status'] !== GateStatus::Active) {
            throw ValidationException::withMessages(['gate_id' => "La puerta {$gate['name']} no está activa."]);
        }

        if ($gate['direction'] !== $direction && $gate['direction'] !== GateDirection::Bidirectional) {
            $label = $direction === GateDirection::Entry ? 'entradas' : 'salidas';

            throw ValidationException::withMessages(['gate_id' => "La puerta {$gate['name']} no registra {$label}."]);
        }
    }
}
