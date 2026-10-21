<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Devices\Domain\DeviceCode;
use App\Modules\Devices\Domain\DeviceStatus;
use App\Modules\Devices\Infrastructure\Persistence\Device;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegisterDevice
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly DevicePlacement $placement,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, DeviceData $data): Device
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::DEVICES_MANAGE);

        $this->placement->assertValid($tenantId, $data->parkingLotId, $data->gateId);
        $code = DeviceCode::normalize($data->code);

        if (Device::query()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            throw self::codeTaken();
        }

        try {
            // A device stays unknown until Nuvora Edge reports it.
            // Own transaction: a failed insert must not abort an enclosing one.
            $device = DB::transaction(fn (): Device => Device::query()->create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'status' => DeviceStatus::Unknown,
                ...DeviceAttributes::from($data),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw self::codeTaken();
        }

        $this->audit->record(
            action: 'device.created',
            resourceType: 'device',
            resourceId: (string) $device->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'code' => $device->code,
                'type' => $device->type->value,
                'parking_lot_id' => $device->parking_lot_id,
                'gate_id' => $device->gate_id,
            ],
        );

        return $device;
    }

    public static function codeTaken(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'Ya existe un dispositivo con ese código en la empresa.']);
    }
}
