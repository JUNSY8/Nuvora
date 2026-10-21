<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class UpdateParkingZone
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
        string $zoneId,
        string $name,
        string $code,
        ?string $description,
        bool $isActive,
        int $sortOrder,
    ): ParkingZone {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_UPDATE);

        $zone = $this->lookup->zone($tenantId, $lotId, $zoneId);
        $code = ParkingCode::normalize($code);
        $before = ['name' => $zone->name, 'code' => $zone->code, 'is_active' => $zone->is_active];

        UniqueCode::guard(
            'Ya existe una zona con ese código en este parqueadero.',
            fn (): bool => ParkingZone::query()
                ->where('parking_lot_id', $zone->parking_lot_id)
                ->where('code', $code)
                ->whereKeyNot($zone->id)
                ->exists(),
            fn (): bool => $zone->fill([
                'name' => trim($name),
                'code' => $code,
                'description' => $description === null || trim($description) === '' ? null : trim($description),
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ])->save(),
        );

        $this->audit->record(
            action: 'parking_zone.updated',
            resourceType: 'parking_zone',
            resourceId: (string) $zone->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'before' => $before,
                'after' => ['name' => $zone->name, 'code' => $zone->code, 'is_active' => $zone->is_active],
            ],
        );

        return $zone->loadCount('spaces');
    }
}
