<?php

declare(strict_types=1);

namespace App\Modules\Devices\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class RemoveDevice
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly DeviceLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $deviceId): void
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::DEVICES_MANAGE);

        $device = $this->lookup->find($tenantId, $deviceId);
        $device->delete();

        $this->audit->record(
            action: 'device.deleted',
            resourceType: 'device',
            resourceId: (string) $device->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['code' => $device->code, 'type' => $device->type->value],
        );
    }
}
