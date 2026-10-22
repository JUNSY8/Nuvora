<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use App\Modules\Parking\Domain\Occupancy;
use App\Modules\Parking\Domain\ParkingLotStatus;
use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;

/**
 * Public contract of the Parking module for other modules (Devices,
 * Sessions and Tariffs). It only answers questions scoped to a tenant.
 */
final class ParkingInfrastructureDirectory
{
    public function __construct(private readonly ParkingOccupancyReader $occupancy) {}

    public function lotExists(string $tenantId, string $lotId): bool
    {
        return ParkingLot::query()->where('tenant_id', $tenantId)->whereKey($lotId)->exists();
    }

    /**
     * @return array{id: string, name: string, code: string, timezone: string, currency: string, grace_period_minutes: int, status: ParkingLotStatus}|null
     */
    public function lot(string $tenantId, string $lotId): ?array
    {
        $lot = ParkingLot::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($lotId)
            ->first(['id', 'name', 'code', 'timezone', 'currency', 'grace_period_minutes', 'status']);

        return $lot === null ? null : [
            'id' => (string) $lot->id,
            'name' => $lot->name,
            'code' => $lot->code,
            'timezone' => $lot->timezone,
            'currency' => $lot->currency,
            'grace_period_minutes' => $lot->grace_period_minutes,
            'status' => $lot->status,
        ];
    }

    /**
     * @return array{id: string, name: string, code: string}|null
     */
    public function zone(string $tenantId, string $lotId, string $zoneId): ?array
    {
        $zone = ParkingZone::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($zoneId)
            ->first(['id', 'name', 'code']);

        return $zone === null ? null : ['id' => (string) $zone->id, 'name' => $zone->name, 'code' => $zone->code];
    }

    /**
     * Zone a space belongs to today, used to price stays by zone.
     */
    public function spaceZoneId(string $tenantId, string $lotId, string $spaceId): ?string
    {
        $zoneId = ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($spaceId)
            ->value('zone_id');

        return $zoneId === null ? null : (string) $zoneId;
    }

    /**
     * @param  list<string>  $zoneIds
     * @return array<string, array{id: string, name: string, code: string}>
     */
    public function zoneSummaries(string $tenantId, array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        return ParkingZone::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $zoneIds)
            ->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (ParkingZone $zone): array => [
                (string) $zone->id => ['id' => (string) $zone->id, 'name' => $zone->name, 'code' => $zone->code],
            ])
            ->all();
    }

    /**
     * @return array{id: string, name: string, code: string, direction: GateDirection, status: GateStatus}|null
     */
    public function gate(string $tenantId, string $lotId, string $gateId): ?array
    {
        $gate = ParkingGate::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($gateId)
            ->first(['id', 'name', 'code', 'direction', 'status']);

        return $gate === null ? null : [
            'id' => (string) $gate->id,
            'name' => $gate->name,
            'code' => $gate->code,
            'direction' => $gate->direction,
            'status' => $gate->status,
        ];
    }

    public function occupancy(string $tenantId, string $lotId): Occupancy
    {
        return $this->occupancy->forLots($tenantId, [$lotId])[$lotId];
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
