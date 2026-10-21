<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeVehicleStatus
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly VehicleLookup $lookup,
        private readonly VehicleTimeline $timeline,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $vehicleId, VehicleStatus $target, ?string $reason): Vehicle
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_UPDATE);

        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        if ($target->requiresReason() && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo del bloqueo.']);
        }

        /** @var array{0: Vehicle, 1: VehicleStatus|null} $result */
        $result = DB::transaction(function () use ($tenantId, $vehicleId, $target, $reason, $actorUserId): array {
            $vehicle = $this->lookup->find($tenantId, $vehicleId, lock: true);
            $from = $vehicle->status;

            if ($from === $target && $vehicle->status_reason === $reason) {
                return [$vehicle, null];
            }

            $vehicle->fill([
                'status' => $target,
                'status_reason' => $reason,
                'status_changed_at' => now(),
            ])->save();

            $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::StatusChanged, $actorUserId, [
                'from' => $from->value,
                'to' => $target->value,
                'reason' => $reason,
            ]);

            return [$vehicle, $from];
        });

        [$vehicle, $from] = $result;

        if ($from !== null) {
            $this->audit->record(
                action: 'vehicle.status_changed',
                resourceType: 'vehicle',
                resourceId: (string) $vehicle->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['plate' => $vehicle->plate, 'from' => $from->value, 'to' => $target->value, 'reason' => $reason],
            );
        }

        return $vehicle;
    }
}
