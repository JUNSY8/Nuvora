<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

/**
 * Creates a gate when $gateId is null, otherwise updates it.
 */
final class SaveParkingGate
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        string $actorUserId,
        string $tenantId,
        string $lotId,
        ?string $gateId,
        string $name,
        string $code,
        GateDirection $direction,
        GateStatus $status,
    ): ParkingGate {
        $creating = $gateId === null;
        $this->authorizer->assert($actorUserId, $tenantId, $creating ? Permission::PARKING_CREATE : Permission::PARKING_UPDATE);

        $lot = $this->lookup->lot($tenantId, $lotId);
        $gate = $creating ? new ParkingGate(['tenant_id' => $tenantId, 'parking_lot_id' => $lot->id]) : $this->lookup->gate($tenantId, $lotId, $gateId);
        $code = ParkingCode::normalize($code);
        $before = $creating ? null : ['name' => $gate->name, 'code' => $gate->code, 'direction' => $gate->direction->value, 'status' => $gate->status->value];

        UniqueCode::guard(
            'Ya existe una puerta con ese código en este parqueadero.',
            fn (): bool => ParkingGate::query()
                ->where('parking_lot_id', $lot->id)
                ->where('code', $code)
                ->when(! $creating, fn ($query) => $query->whereKeyNot($gate->id))
                ->exists(),
            fn (): bool => $gate->fill([
                'name' => trim($name),
                'code' => $code,
                'direction' => $direction,
                'status' => $status,
            ])->save(),
        );

        $after = ['name' => $gate->name, 'code' => $gate->code, 'direction' => $gate->direction->value, 'status' => $gate->status->value];

        $this->audit->record(
            action: $creating ? 'parking_gate.created' : 'parking_gate.updated',
            resourceType: 'parking_gate',
            resourceId: (string) $gate->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: $creating
                ? ['parking_lot_id' => (string) $lot->id, ...$after]
                : ['before' => $before, 'after' => $after],
        );

        return $gate;
    }
}
