<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Presentation\Http;

use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Infrastructure\Persistence\CustomerVehicle;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use App\Modules\Vehicles\Infrastructure\Persistence\VehicleEvent;
use App\Modules\Vehicles\Infrastructure\Plates\CountryPlateRules;

final class VehiclePayload
{
    /**
     * @param  array<string, array{id: string, name: string, type: string, status: string}>  $customers
     * @return array<string, mixed>
     */
    public static function from(Vehicle $vehicle, array $customers, CountryPlateRules $rules): array
    {
        $format = $rules->recognize($vehicle->country, Plate::fromInput($vehicle->plate));

        return [
            'id' => $vehicle->id,
            'plate' => $vehicle->plate,
            'country' => $vehicle->country,
            'plate_format' => $format === null ? null : ['key' => $format->key, 'label' => $format->label],
            'type' => $vehicle->type->value,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'color' => $vehicle->color,
            'year' => $vehicle->year,
            'is_electric' => $vehicle->is_electric,
            'status' => $vehicle->status->value,
            'status_reason' => $vehicle->status_reason,
            'status_changed_at' => $vehicle->status_changed_at?->toIso8601String(),
            'notes' => $vehicle->notes,
            'links' => $vehicle->relationLoaded('activeLinks')
                ? $vehicle->activeLinks->map(fn (CustomerVehicle $link): array => self::link($link, $customers))->values()->all()
                : [],
            'created_at' => $vehicle->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{id: string, name: string, type: string, status: string}>  $customers
     * @return array<string, mixed>
     */
    public static function link(CustomerVehicle $link, array $customers): array
    {
        return [
            'id' => $link->id,
            'relationship' => $link->relationship->value,
            'started_at' => $link->started_at->toIso8601String(),
            'customer' => $customers[(string) $link->customer_id] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(VehicleEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'data' => $event->data ?? (object) [],
            'actor' => $event->actor === null ? null : ['id' => $event->actor->id, 'name' => $event->actor->name],
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }
}
