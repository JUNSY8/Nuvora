<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;
use Illuminate\Validation\ValidationException;

/**
 * Loads parking resources only through the active tenant. A resource of
 * another tenant is reported as missing, never as forbidden.
 */
final class ParkingLookup
{
    public function lot(string $tenantId, string $lotId, bool $lock = false): ParkingLot
    {
        $query = ParkingLot::query()->where('tenant_id', $tenantId)->whereKey($lotId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function zone(string $tenantId, string $lotId, string $zoneId): ParkingZone
    {
        return ParkingZone::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($zoneId)
            ->firstOrFail();
    }

    public function space(string $tenantId, string $lotId, string $spaceId, bool $lock = false): ParkingSpace
    {
        $query = ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($spaceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function gate(string $tenantId, string $lotId, string $gateId): ParkingGate
    {
        return ParkingGate::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($gateId)
            ->firstOrFail();
    }

    /**
     * Validates a zone chosen in a form. Unlike a URL resource, a bad value
     * here is a validation error on the field.
     */
    public function assertZoneInLot(string $tenantId, string $lotId, ?string $zoneId): void
    {
        if ($zoneId === null) {
            return;
        }

        $exists = ParkingZone::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($zoneId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'zone_id' => 'La zona no pertenece a este parqueadero.',
            ]);
        }
    }
}
