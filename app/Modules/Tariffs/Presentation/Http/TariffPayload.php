<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Presentation\Http;

use App\Modules\Tariffs\Infrastructure\Persistence\Discount;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;
use App\Modules\Tariffs\Infrastructure\Persistence\TariffVersion;

final class TariffPayload
{
    /**
     * @param  array<string, array{id: string, name: string, code: string}>  $zones
     * @return array<string, mixed>
     */
    public static function tariff(Tariff $tariff, TariffVersion $current, array $zones): array
    {
        return [
            'id' => $tariff->id,
            'parking_lot_id' => $tariff->parking_lot_id,
            'currency' => $tariff->currency,
            'status' => $tariff->status->value,
            'version' => $tariff->current_version,
            'effective_from' => $current->effective_from->toIso8601String(),
            ...self::rules($current, $zones),
            'created_at' => $tariff->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{id: string, name: string, code: string}>  $zones
     * @return array<string, mixed>
     */
    public static function version(TariffVersion $version, array $zones): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status->value,
            'effective_from' => $version->effective_from->toIso8601String(),
            ...self::rules($version, $zones),
            'change_note' => $version->change_note,
            'author' => $version->author === null ? null : ['id' => $version->author->id, 'name' => $version->author->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function discount(Discount $discount): array
    {
        return [
            'id' => $discount->id,
            'parking_lot_id' => $discount->parking_lot_id,
            'code' => $discount->code,
            'name' => $discount->name,
            'type' => $discount->type->value,
            'value' => $discount->value,
            'currency' => $discount->currency,
            'application' => $discount->application->value,
            'status' => $discount->status->value,
            'vehicle_types' => $discount->vehicle_types,
            'days' => $discount->days,
            'valid_from' => $discount->valid_from === null ? null : substr((string) $discount->valid_from, 0, 10),
            'valid_until' => $discount->valid_until === null ? null : substr((string) $discount->valid_until, 0, 10),
        ];
    }

    /**
     * @param  array<string, array{id: string, name: string, code: string}>  $zones
     * @return array<string, mixed>
     */
    private static function rules(TariffVersion $version, array $zones): array
    {
        $zoneId = $version->zone_id === null ? null : (string) $version->zone_id;

        return [
            'code' => $version->code,
            'name' => $version->name,
            'priority' => $version->priority,
            'vehicle_types' => $version->vehicle_types,
            'zone' => $zoneId === null ? null : ($zones[$zoneId] ?? ['id' => $zoneId, 'name' => null, 'code' => null]),
            'windows' => $version->windows,
            'grace_minutes' => $version->grace_minutes,
            'brackets' => $version->brackets,
            'step_minutes' => $version->step_minutes,
            'step_amount' => $version->step_amount,
            'daily_max_amount' => $version->daily_max_amount,
        ];
    }
}
