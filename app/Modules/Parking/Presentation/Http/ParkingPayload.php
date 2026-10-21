<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http;

use App\Modules\Parking\Domain\Occupancy;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLotOperatingHour;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;

final class ParkingPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function lot(ParkingLot $lot, ?Occupancy $occupancy = null): array
    {
        $payload = [
            'id' => $lot->id,
            'name' => $lot->name,
            'code' => $lot->code,
            'description' => $lot->description,
            'address' => $lot->address,
            'city' => $lot->city,
            'latitude' => $lot->latitude,
            'longitude' => $lot->longitude,
            'timezone' => $lot->timezone,
            'currency' => $lot->currency,
            'grace_period_minutes' => $lot->grace_period_minutes,
            'status' => $lot->status->value,
            'zones_count' => (int) ($lot->zones_count ?? 0),
            'gates_count' => (int) ($lot->gates_count ?? 0),
            'occupancy' => $occupancy === null ? null : self::occupancy($occupancy),
            'created_at' => $lot->created_at?->toIso8601String(),
            'updated_at' => $lot->updated_at?->toIso8601String(),
        ];

        if ($lot->relationLoaded('operatingHours')) {
            $payload['operating_hours'] = $lot->operatingHours
                ->map(fn (ParkingLotOperatingHour $hour): array => self::hour($hour))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function occupancy(Occupancy $occupancy): array
    {
        return [
            'total' => $occupancy->total,
            'capacity' => $occupancy->capacity,
            'available' => $occupancy->available,
            'in_use' => $occupancy->inUse,
            'rate' => $occupancy->rate(),
            'by_status' => $occupancy->byStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function hour(ParkingLotOperatingHour $hour): array
    {
        return [
            'day_of_week' => $hour->day_of_week,
            'mode' => $hour->mode->value,
            'opens_at' => $hour->opens_at === null ? null : substr((string) $hour->opens_at, 0, 5),
            'closes_at' => $hour->closes_at === null ? null : substr((string) $hour->closes_at, 0, 5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function zone(ParkingZone $zone): array
    {
        return [
            'id' => $zone->id,
            'parking_lot_id' => $zone->parking_lot_id,
            'name' => $zone->name,
            'code' => $zone->code,
            'description' => $zone->description,
            'is_active' => $zone->is_active,
            'sort_order' => $zone->sort_order,
            'spaces_count' => (int) ($zone->spaces_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function space(ParkingSpace $space): array
    {
        $zone = $space->relationLoaded('zone') ? $space->zone : null;

        return [
            'id' => $space->id,
            'parking_lot_id' => $space->parking_lot_id,
            'code' => $space->code,
            'type' => $space->type->value,
            'status' => $space->status->value,
            'floor' => $space->floor,
            'is_reservable' => $space->is_reservable,
            'is_assignable' => $space->is_assignable,
            'zone' => $zone === null ? null : ['id' => $zone->id, 'name' => $zone->name, 'code' => $zone->code],
            'status_changed_at' => $space->status_changed_at?->toIso8601String(),
            // The UI offers only what the state machine accepts from here.
            'manual_transitions' => array_map(
                fn (SpaceStatus $status): string => $status->value,
                $space->status->manualTransitions(),
            ),
            'removable' => $space->status->isRemovable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function gate(ParkingGate $gate): array
    {
        return [
            'id' => $gate->id,
            'parking_lot_id' => $gate->parking_lot_id,
            'name' => $gate->name,
            'code' => $gate->code,
            'direction' => $gate->direction->value,
            'status' => $gate->status->value,
        ];
    }
}
