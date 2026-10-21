<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;

/**
 * Public contract of the Parking module for other modules (Devices today,
 * Access and Sessions later). It only answers questions scoped to a tenant.
 */
final class ParkingInfrastructureDirectory
{
    public function lotExists(string $tenantId, string $lotId): bool
    {
        return ParkingLot::query()->where('tenant_id', $tenantId)->whereKey($lotId)->exists();
    }

    public function gateBelongsToLot(string $tenantId, string $lotId, string $gateId): bool
    {
        return ParkingGate::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($gateId)
            ->exists();
    }

    /**
     * @param  list<string>  $lotIds
     * @return array<string, array{id: string, name: string, code: string}>
     */
    public function lotSummaries(string $tenantId, array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        return ParkingLot::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $lotIds)
            ->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (ParkingLot $lot): array => [
                (string) $lot->id => ['id' => (string) $lot->id, 'name' => $lot->name, 'code' => $lot->code],
            ])
            ->all();
    }

    /**
     * @param  list<string>  $gateIds
     * @return array<string, array{id: string, name: string, code: string}>
     */
    public function gateSummaries(string $tenantId, array $gateIds): array
    {
        if ($gateIds === []) {
            return [];
        }

        return ParkingGate::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $gateIds)
            ->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (ParkingGate $gate): array => [
                (string) $gate->id => ['id' => (string) $gate->id, 'name' => $gate->name, 'code' => $gate->code],
            ])
            ->all();
    }
}
