<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

final class ChangeParkingSpaceStatus
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Administrative status change (block, maintenance, out of service). Setting
     * the status a space already has is accepted and changes nothing.
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId, string $spaceId, SpaceStatus $target, ?string $reason): ParkingSpace
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_UPDATE);

        $result = DB::transaction(function () use ($tenantId, $lotId, $spaceId, $target): array {
            // Lock so a concurrent session check-in cannot interleave with this change.
            $space = $this->lookup->space($tenantId, $lotId, $spaceId, lock: true);
            $from = $space->status;

            if ($from === $target) {
                return [$space, null];
            }

            if (! $from->isManagedManually()) {
                throw new ParkingRuleViolation(
                    'El espacio está ocupado o reservado. Su estado cambia con la salida o la cancelación.',
                    'SPACE_TRANSITION_NOT_ALLOWED',
                );
            }

            if (! in_array($target, $from->manualTransitions(), true)) {
                throw new ParkingRuleViolation('Ese cambio de estado no está permitido.', 'SPACE_TRANSITION_NOT_ALLOWED');
            }

            $space->fill(['status' => $target, 'status_changed_at' => now()])->save();

            return [$space, $from];
        });

        /** @var ParkingSpace $space */
        [$space, $from] = $result;

        if ($from instanceof SpaceStatus) {
            $reason = $reason === null ? null : trim($reason);

            $this->audit->record(
                action: 'parking_space.status_changed',
                resourceType: 'parking_space',
                resourceId: (string) $space->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: [
                    'code' => $space->code,
                    'from' => $from->value,
                    'to' => $space->status->value,
                    'reason' => $reason === '' ? null : $reason,
                ],
            );
        }

        return $space->load('zone:id,name,code');
    }
}
