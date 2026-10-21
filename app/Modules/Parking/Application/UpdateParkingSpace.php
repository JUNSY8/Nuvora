<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class UpdateParkingSpace
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, string $spaceId, string $code, ParkingSpaceData $data): ParkingSpace
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_UPDATE);

        $space = $this->lookup->space($tenantId, $lotId, $spaceId);
        $this->lookup->assertZoneInLot($tenantId, (string) $space->parking_lot_id, $data->zoneId);
        $code = ParkingCode::normalize($code);

        $before = $this->snapshot($space);

        UniqueCode::guard(
            'Ya existe un espacio con ese código en este parqueadero.',
            fn (): bool => ParkingSpace::query()
                ->where('parking_lot_id', $space->parking_lot_id)
                ->where('code', $code)
                ->whereKeyNot($space->id)
                ->exists(),
            fn (): bool => $space->fill([
                'zone_id' => $data->zoneId,
                'code' => $code,
                'type' => $data->type,
                'floor' => $data->floor,
                'is_reservable' => $data->isReservable,
                'is_assignable' => $data->isAssignable,
            ])->save(),
        );

        $this->audit->record(
            action: 'parking_space.updated',
            resourceType: 'parking_space',
            resourceId: (string) $space->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['before' => $before, 'after' => $this->snapshot($space)],
        );

        return $space->load('zone:id,name,code');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ParkingSpace $space): array
    {
        return [
            'code' => $space->code,
            'zone_id' => $space->zone_id,
            'type' => $space->type->value,
            'floor' => $space->floor,
            'is_reservable' => $space->is_reservable,
            'is_assignable' => $space->is_assignable,
        ];
    }
}
