<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

final class DeleteParkingSpace
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $lotId, string $spaceId): void
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_DELETE);

        /** @var ParkingSpace $space */
        $space = DB::transaction(function () use ($tenantId, $lotId, $spaceId): ParkingSpace {
            $space = $this->lookup->space($tenantId, $lotId, $spaceId, lock: true);

            if (! $space->status->isRemovable()) {
                throw new ParkingRuleViolation('No puedes retirar un espacio ocupado o reservado.', 'SPACE_IN_USE');
            }

            // Parking sessions keep a restrictive reference to the space they used.
            ForeignKeyGuard::delete(
                fn () => $space->delete(),
                'El espacio tiene sesiones registradas. Márcalo fuera de servicio en lugar de eliminarlo.',
                'SPACE_HAS_HISTORY',
            );

            return $space;
        });

        $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, (string) $space->parking_lot_id));

        $this->audit->record(
            action: 'parking_space.deleted',
            resourceType: 'parking_space',
            resourceId: (string) $space->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['parking_lot_id' => (string) $space->parking_lot_id, 'code' => $space->code],
        );
    }
}
