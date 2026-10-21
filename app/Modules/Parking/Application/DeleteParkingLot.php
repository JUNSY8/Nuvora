<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class DeleteParkingLot
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId): void
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_DELETE);

        $lot = $this->lookup->lot($tenantId, $lotId);

        $hasChildren = ParkingSpace::query()->where('parking_lot_id', $lot->id)->exists()
            || ParkingGate::query()->where('parking_lot_id', $lot->id)->exists();

        $message = 'Retira primero los espacios, puertas y dispositivos del parqueadero. Si solo quieres dejar de operarlo, márcalo como inactivo.';

        if ($hasChildren) {
            throw new ParkingRuleViolation($message, 'PARKING_LOT_NOT_EMPTY');
        }

        // Zones and operating hours cascade; devices are guarded by their foreign key.
        ForeignKeyGuard::delete(fn () => $lot->delete(), $message, 'PARKING_LOT_NOT_EMPTY');

        $this->audit->record(
            action: 'parking_lot.deleted',
            resourceType: 'parking_lot',
            resourceId: (string) $lot->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['code' => $lot->code, 'name' => $lot->name],
        );
    }
}
