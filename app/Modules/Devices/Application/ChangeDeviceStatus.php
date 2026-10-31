<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Devices\Domain\DeviceRuleViolation;
use App\Modules\Devices\Domain\DeviceStatus;
use App\Modules\Devices\Infrastructure\Persistence\Device;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

final class ChangeDeviceStatus
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly DeviceLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $deviceId, DeviceStatus $target): Device
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::DEVICES_MANAGE);

        /** @var array{0: Device, 1: DeviceStatus|null} $result */
        $result = DB::transaction(function () use ($tenantId, $deviceId, $target): array {
            $device = $this->lookup->find($tenantId, $deviceId, lock: true);
            $from = $device->status;

            if ($from === $target) {
                return [$device, null];
            }

            if (! $from->canBeSetManuallyTo($target)) {
                throw new DeviceRuleViolation(
                    'Solo puedes poner un dispositivo en mantenimiento o devolverlo a monitoreo.',
                    'DEVICE_TRANSITION_NOT_ALLOWED',
                );
            }

            $device->fill(['status' => $target])->save();

            return [$device, $from];
        });

        [$device, $from] = $result;

        if ($from !== null) {
            $this->audit->record(
                action: 'device.status_changed',
                resourceType: 'device',
                resourceId: (string) $device->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['code' => $device->code, 'from' => $from->value, 'to' => $device->status->value],
            );
        }

        return $device;
    }
}
