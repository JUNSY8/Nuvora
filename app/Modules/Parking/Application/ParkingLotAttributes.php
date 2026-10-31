<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

final class ParkingLotAttributes
{
    /**
     * Attributes shared by create and update, code excluded.
     *
     * @return array<string, mixed>
     */
    public static function from(ParkingLotData $data): array
    {
        return [
            'name' => trim($data->name),
            'description' => self::nullable($data->description),
            'address' => self::nullable($data->address),
            'city' => self::nullable($data->city),
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'timezone' => $data->timezone,
            'currency' => strtoupper($data->currency),
            'grace_period_minutes' => $data->gracePeriodMinutes,
            'status' => $data->status,
        ];
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
