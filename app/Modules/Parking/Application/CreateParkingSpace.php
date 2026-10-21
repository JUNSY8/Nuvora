<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class CreateParkingSpace
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, string $code, ParkingSpaceData $data): ParkingSpace
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_CREATE);

        $lot = $this->lookup->lot($tenantId, $lotId);
        $this->lookup->assertZoneInLot($tenantId, (string) $lot->id, $data->zoneId);
        $code = ParkingCode::normalize($code);

        $space = UniqueCode::guard(
            'Ya existe un espacio con ese código en este parqueadero.',
            fn (): bool => ParkingSpace::query()->where('parking_lot_id', $lot->id)->where('code', $code)->exists(),
            fn (): ParkingSpace => ParkingSpace::query()->create([
                'tenant_id' => $tenantId,
                'parking_lot_id' => $lot->id,
                'zone_id' => $data->zoneId,
                'code' => $code,
                'type' => $data->type,
                'status' => SpaceStatus::Available,
                'floor' => $data->floor,
                'is_reservable' => $data->isReservable,
                'is_assignable' => $data->isAssignable,
                'status_changed_at' => now(),
            ]),
        );

        $this->audit->record(
            action: 'parking_space.created',
            resourceType: 'parking_space',
            resourceId: (string) $space->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['parking_lot_id' => (string) $lot->id, 'code' => $space->code, 'type' => $space->type->value],
        );

        return $space->load('zone:id,name,code');
    }
}
