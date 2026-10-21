<?php

declare(strict_types=1);

namespace App\Modules\Devices\Presentation\Http;

use App\Modules\Devices\Domain\DeviceStatus;
use App\Modules\Devices\Infrastructure\Persistence\Device;

final class DevicePayload
{
    /**
     * @param  array{lots: array<string, array{id: string, name: string, code: string}>, gates: array<string, array{id: string, name: string, code: string}>}  $summaries
     * @return array<string, mixed>
     */
    public static function from(Device $device, array $summaries): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'code' => $device->code,
            'type' => $device->type->value,
            'vendor' => $device->vendor,
            'model' => $device->model,
            'serial_number' => $device->serial_number,
            'ip_address' => $device->ip_address,
            'firmware_version' => $device->firmware_version,
            'status' => $device->status->value,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'parking_lot' => $summaries['lots'][(string) $device->parking_lot_id] ?? null,
            'gate' => $device->gate_id === null ? null : ($summaries['gates'][(string) $device->gate_id] ?? null),
            'manual_transitions' => array_map(
                fn (DeviceStatus $status): string => $status->value,
                $device->status->manualTransitions(),
            ),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }
}
