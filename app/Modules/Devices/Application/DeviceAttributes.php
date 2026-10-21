<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

final class DeviceAttributes
{
    /**
     * @return array<string, mixed>
     */
    public static function from(DeviceData $data): array
    {
        return [
            'parking_lot_id' => $data->parkingLotId,
            'gate_id' => $data->gateId,
            'name' => trim($data->name),
            'type' => $data->type,
            'vendor' => self::nullable($data->vendor),
            'model' => self::nullable($data->model),
            'serial_number' => self::nullable($data->serialNumber),
            'ip_address' => self::nullable($data->ipAddress),
            'firmware_version' => self::nullable($data->firmwareVersion),
        ];
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
