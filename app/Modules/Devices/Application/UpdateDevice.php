<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Devices\Domain\DeviceCode;
use App\Modules\Devices\Infrastructure\Persistence\Device;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class UpdateDevice
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly DeviceLookup $lookup,
        private readonly DevicePlacement $placement,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $deviceId, DeviceData $data): Device
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::DEVICES_MANAGE);

        $device = $this->lookup->find($tenantId, $deviceId);
        $this->placement->assertValid($tenantId, $data->parkingLotId, $data->gateId);
        $code = DeviceCode::normalize($data->code);

        $taken = Device::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->whereKeyNot($device->id)
            ->exists();

        if ($taken) {
            throw RegisterDevice::codeTaken();
        }

        $before = $this->snapshot($device);

        try {
            DB::transaction(fn (): bool => $device->fill(['code' => $code, ...DeviceAttributes::from($data)])->save());
        } catch (UniqueConstraintViolationException) {
            throw RegisterDevice::codeTaken();
        }

        $this->audit->record(
            action: 'device.updated',
            resourceType: 'device',
            resourceId: (string) $device->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['before' => $before, 'after' => $this->snapshot($device)],
        );

        return $device;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Device $device): array
    {
        return [
            'code' => $device->code,
            'name' => $device->name,
            'type' => $device->type->value,
            'parking_lot_id' => $device->parking_lot_id,
            'gate_id' => $device->gate_id,
            'ip_address' => $device->ip_address,
            'firmware_version' => $device->firmware_version,
        ];
    }
}
