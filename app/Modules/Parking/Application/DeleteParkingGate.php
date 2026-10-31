<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class DeleteParkingGate
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, string $gateId): void
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_DELETE);

        $gate = $this->lookup->gate($tenantId, $lotId, $gateId);

        ForeignKeyGuard::delete(
            fn () => $gate->delete(),
            'La puerta tiene dispositivos asociados o sesiones registradas. Retira los dispositivos o márcala inactiva.',
            'GATE_IN_USE',
        );

        $this->audit->record(
            action: 'parking_gate.deleted',
            resourceType: 'parking_gate',
            resourceId: (string) $gate->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['parking_lot_id' => (string) $gate->parking_lot_id, 'code' => $gate->code],
        );
    }
}
