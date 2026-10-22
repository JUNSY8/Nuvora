<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Reservations\Domain\ReservationPolicy;
use App\Modules\Reservations\Infrastructure\Persistence\LotReservationPolicy;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Validation\ValidationException;

/**
 * Reservation rules per lot. A lot without its own row uses the defaults,
 * so reservations work as soon as it has reservable spaces. Changing the
 * rules is lot configuration and needs parking.update.
 */
final class ReservationPolicies
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function for(string $tenantId, string $lotId): ReservationPolicy
    {
        $row = LotReservationPolicy::query()->where('tenant_id', $tenantId)->where('parking_lot_id', $lotId)->first();

        return $row === null ? ReservationPolicy::defaults() : ReservationPolicy::create(
            $row->enabled,
            $row->buffer_minutes,
            $row->hold_minutes,
            $row->arrival_tolerance_minutes,
            $row->min_duration_minutes,
            $row->max_duration_minutes,
            $row->max_advance_days,
            $row->within_operating_hours,
        );
    }

    public function show(string $actorUserId, string $tenantId, string $lotId): ReservationPolicy
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::RESERVATIONS_VIEW);
        $this->lot($tenantId, $lotId);

        return $this->for($tenantId, $lotId);
    }

    public function update(string $actorUserId, string $tenantId, string $lotId, ReservationPolicy $policy): ReservationPolicy
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_UPDATE);
        $this->lot($tenantId, $lotId);

        $before = $this->for($tenantId, $lotId)->toArray();

        LotReservationPolicy::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'parking_lot_id' => $lotId],
            $policy->toArray(),
        );

        $this->audit->record(
            action: 'reservation_policy.updated',
            resourceType: 'parking_lot',
            resourceId: $lotId,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['before' => $before, 'after' => $policy->toArray()],
        );

        return $policy;
    }

    private function lot(string $tenantId, string $lotId): void
    {
        if (! $this->parking->lotExists($tenantId, $lotId)) {
            throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);
        }
    }
}
