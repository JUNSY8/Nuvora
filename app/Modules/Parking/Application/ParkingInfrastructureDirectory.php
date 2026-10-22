<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use App\Modules\Parking\Domain\Occupancy;
use App\Modules\Parking\Domain\OperatingDay;
use App\Modules\Parking\Domain\ParkingLotStatus;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\Parking\Domain\WeeklySchedule;
use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLotOperatingHour;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Public contract of the Parking module for other modules (Devices,
 * Sessions, Tariffs and Reservations). It only answers questions scoped to a tenant.
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
     * Whether the lot is open for the whole interval, read from its weekly
     * schedule in its own timezone. A lot without schedule is always open.
     */
    public function opensThroughout(string $tenantId, string $lotId, string $timezone, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        $days = ParkingLotOperatingHour::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->get()
            ->map(fn (ParkingLotOperatingHour $hour): OperatingDay => OperatingDay::create($hour->day_of_week, $hour->mode, $hour->opens_at, $hour->closes_at))
            ->all();

        $schedule = count($days) === 7 ? WeeklySchedule::fromDays($days) : WeeklySchedule::alwaysOpen();
        $zone = new DateTimeZone($timezone);

        return $schedule->covers($from->setTimezone($zone), $to->setTimezone($zone));
    }

    /**
     * The reservation pool: reservable spaces of the lot (or of one zone)
     * that are in operation. Blocked, maintenance and out-of-service spaces
     * cannot be promised.
     *
     * @return list<array{id: string, code: string, zone_id: string|null, type: SpaceType, status: SpaceStatus}>
     */
    public function reservableSpaces(string $tenantId, string $lotId, ?string $zoneId = null): array
    {
        return ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->where('is_reservable', true)
            ->whereIn('status', [SpaceStatus::Available->value, SpaceStatus::Occupied->value, SpaceStatus::Reserved->value])
            ->when($zoneId !== null, fn ($query) => $query->where('zone_id', $zoneId))
            ->orderBy('code')
            ->get(['id', 'code', 'zone_id', 'type', 'status'])
            ->map(fn (ParkingSpace $space): array => self::spaceSummary($space))
            ->all();
    }

    /**
     * @return array{id: string, code: string, zone_id: string|null, type: SpaceType, status: SpaceStatus, is_reservable: bool}|null
     */
    public function space(string $tenantId, string $lotId, string $spaceId): ?array
    {
        $space = ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($spaceId)
            ->first(['id', 'code', 'zone_id', 'type', 'status', 'is_reservable']);

        return $space === null ? null : [...self::spaceSummary($space), 'is_reservable' => $space->is_reservable];
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
     * @return array{id: string, code: string, zone_id: string|null, type: SpaceType, status: SpaceStatus}
     */
    private static function spaceSummary(ParkingSpace $space): array
    {
        return [
            'id' => (string) $space->id,
            'code' => $space->code,
            'zone_id' => $space->zone_id === null ? null : (string) $space->zone_id,
            'type' => $space->type,
            'status' => $space->status,
        ];
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
