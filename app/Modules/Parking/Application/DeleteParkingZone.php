<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class DeleteParkingZone
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, string $zoneId): void
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_DELETE);

        $zone = $this->lookup->zone($tenantId, $lotId, $zoneId);
        $message = 'La zona todavía tiene espacios. Muévelos a otra zona o retíralos primero.';

        if (ParkingSpace::query()->where('zone_id', $zone->id)->exists()) {
            throw new ParkingRuleViolation($message, 'ZONE_HAS_SPACES');
        }

        // Tariff versions and reservations keep a restrictive reference to the zone.
        ForeignKeyGuard::delete(
            fn () => $zone->delete(),
            'La zona tiene espacios, tarifas o reservas asociadas. Márcala inactiva en lugar de eliminarla.',
            'ZONE_IN_USE',
        );

        $this->audit->record(
            action: 'parking_zone.deleted',
            resourceType: 'parking_zone',
            resourceId: (string) $zone->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['parking_lot_id' => (string) $zone->parking_lot_id, 'code' => $zone->code],
        );
    }
}
