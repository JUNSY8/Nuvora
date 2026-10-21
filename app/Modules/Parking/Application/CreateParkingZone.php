<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class CreateParkingZone
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
        string $name,
        string $code,
        ?string $description,
        bool $isActive,
        int $sortOrder,
    ): ParkingZone {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_CREATE);

        $lot = $this->lookup->lot($tenantId, $lotId);
        $code = ParkingCode::normalize($code);

        $zone = UniqueCode::guard(
            'Ya existe una zona con ese código en este parqueadero.',
            fn (): bool => ParkingZone::query()->where('parking_lot_id', $lot->id)->where('code', $code)->exists(),
            fn (): ParkingZone => ParkingZone::query()->create([
                'tenant_id' => $tenantId,
                'parking_lot_id' => $lot->id,
                'name' => trim($name),
                'code' => $code,
                'description' => $description === null || trim($description) === '' ? null : trim($description),
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]),
        );

        $this->audit->record(
            action: 'parking_zone.created',
            resourceType: 'parking_zone',
            resourceId: (string) $zone->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['parking_lot_id' => (string) $lot->id, 'code' => $zone->code, 'name' => $zone->name],
        );

        return $zone->loadCount('spaces');
    }
}
