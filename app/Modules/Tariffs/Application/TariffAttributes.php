<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Tariffs\Domain\RateSchedule;
use App\Modules\Tariffs\Domain\TariffCode;
use App\Modules\Tariffs\Domain\TimeWindow;
use App\Modules\Tariffs\Infrastructure\Persistence\TariffVersion;
use App\Modules\Vehicles\Domain\VehicleType;

/**
 * The configurable columns of a tariff version, validated by the domain and
 * normalized so two equal configurations compare equal.
 */
final class TariffAttributes
{
    /**
     * @return array<string, mixed>
     */
    public static function from(TariffData $data, string $currency): array
    {
        $rates = RateSchedule::create($data->brackets, $data->stepMinutes, $data->stepAmount, $data->dailyMaxAmount, $currency);
        $windows = array_map(
            fn (mixed $window): array => TimeWindow::fromArray(is_array($window) ? $window : [])->toArray(),
            array_values($data->windows),
        );

        return [
            'code' => TariffCode::normalize($data->code),
            'name' => trim($data->name),
            'priority' => $data->priority,
            'vehicle_types' => self::vehicleTypes($data->vehicleTypes),
            'zone_id' => $data->zoneId,
            'windows' => $windows,
            'grace_minutes' => $data->graceMinutes,
            ...$rates->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofVersion(TariffVersion $version): array
    {
        return [
            'code' => $version->code,
            'name' => $version->name,
            'priority' => $version->priority,
            'vehicle_types' => $version->vehicle_types,
            'zone_id' => $version->zone_id === null ? null : (string) $version->zone_id,
            'windows' => $version->windows,
            'grace_minutes' => $version->grace_minutes,
            'brackets' => $version->brackets,
            'step_minutes' => $version->step_minutes,
            'step_amount' => $version->step_amount,
            'daily_max_amount' => $version->daily_max_amount,
        ];
    }

    /**
     * @param  list<VehicleType>  $types
     * @return list<string>
     */
    private static function vehicleTypes(array $types): array
    {
        return array_values(array_map(
            fn (VehicleType $type): string => $type->value,
            array_filter(VehicleType::cases(), fn (VehicleType $type): bool => in_array($type, $types, true)),
        ));
    }
}
