<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Memberships\Domain\MembershipRuleViolation;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Infrastructure\MembershipLock;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPlan;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipVehicle;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Application\VehicleDirectory;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Support\Facades\DB;

/**
 * Vehicles of a membership. A vehicle belongs to at most one membership in
 * force (active or suspended) at a time, so an arrival always resolves to
 * one membership; the check runs under a per-vehicle lock.
 */
final class MembershipVehicles
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly VehicleDirectory $vehicles,
        private readonly MembershipLookup $lookup,
        private readonly MembershipLock $lock,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function add(string $actorUserId, string $tenantId, string $membershipId, string $plate, string $country, ?VehicleType $type): Membership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_MANAGE);

        $membership = DB::transaction(function () use ($actorUserId, $tenantId, $membershipId, $plate, $country, $type): Membership {
            $membership = $this->lookup->find($tenantId, $membershipId, lock: true);

            if (! in_array($membership->status->value, MembershipStatus::holdingValues(), true)) {
                throw new MembershipRuleViolation("La mensualidad {$membership->code} no está vigente.", 'MEMBERSHIP_NOT_CHANGEABLE');
            }

            $plan = MembershipPlans::find($tenantId, (string) $membership->plan_id);

            if ($membership->vehicles()->count() >= $plan->max_vehicles) {
                throw new MembershipRuleViolation("El plan {$plan->name} admite hasta {$plan->max_vehicles} vehículo(s).", 'TOO_MANY_VEHICLES');
            }

            $this->join($membership, $plan, $actorUserId, $plate, $country, $type);

            return $membership;
        });

        $this->audit->record('membership.vehicle_added', 'membership', (string) $membership->id, $tenantId, $actorUserId, ['code' => $membership->code, 'plate' => strtoupper($plate)]);

        return $membership;
    }

    public function remove(string $actorUserId, string $tenantId, string $membershipId, string $vehicleId): Membership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_MANAGE);

        [$membership, $plate] = DB::transaction(function () use ($actorUserId, $tenantId, $membershipId, $vehicleId): array {
            $membership = $this->lookup->find($tenantId, $membershipId, lock: true);
            $link = $membership->vehicles()->where('vehicle_id', $vehicleId)->first();

            if (! $link instanceof MembershipVehicle) {
                throw new MembershipRuleViolation('El vehículo no está en esta mensualidad.', 'VEHICLE_NOT_IN_MEMBERSHIP');
            }

            if ($membership->vehicles()->count() === 1 && in_array($membership->status->value, MembershipStatus::holdingValues(), true)) {
                throw new MembershipRuleViolation('Una mensualidad vigente necesita al menos un vehículo. Agrega otro antes de retirar este.', 'LAST_VEHICLE');
            }

            $link->fill(['removed_at' => now()])->save();
            $this->vehicles->recordStayEvent($tenantId, $vehicleId, VehicleEventType::MembershipRemoved, $actorUserId, ['membership_id' => (string) $membership->id, 'code' => $membership->code]);

            return [$membership, $link->plate];
        });

        $this->audit->record('membership.vehicle_removed', 'membership', (string) $membership->id, $tenantId, $actorUserId, ['code' => $membership->code, 'plate' => $plate]);

        return $membership;
    }

    /**
     * Identifies (or registers) the vehicle and links it. Runs inside the
     * caller's transaction.
     */
    public function join(Membership $membership, MembershipPlan $plan, string $actorUserId, string $plate, string $country, ?VehicleType $type): MembershipVehicle
    {
        $tenantId = (string) $membership->tenant_id;
        $vehicle = $this->vehicles->identifyOrRegister($tenantId, $actorUserId, $plate, $country, $type, 'membership');

        if ($vehicle['status'] === VehicleStatus::Blocked) {
            throw new MembershipRuleViolation("El vehículo {$vehicle['plate']} está bloqueado.", 'VEHICLE_BLOCKED');
        }

        $rules = MembershipPlans::rules($plan);

        if ($rules->vehicleTypes !== [] && ! in_array($vehicle['type'], $rules->vehicleTypes, true)) {
            throw new MembershipRuleViolation("El plan {$plan->name} no incluye vehículos de tipo {$vehicle['type']->value}.", 'VEHICLE_TYPE_NOT_INCLUDED');
        }

        $this->lock->forVehicle($vehicle['id']);

        $other = MembershipVehicle::query()
            ->join('memberships', 'memberships.id', '=', 'membership_vehicles.membership_id')
            ->where('membership_vehicles.tenant_id', $tenantId)
            ->where('membership_vehicles.vehicle_id', $vehicle['id'])
            ->whereNull('membership_vehicles.removed_at')
            ->whereIn('memberships.status', MembershipStatus::holdingValues())
            ->value('memberships.code');

        if ($other !== null) {
            throw new MembershipRuleViolation("El vehículo {$vehicle['plate']} ya está en la mensualidad {$other}.", 'VEHICLE_ALREADY_MEMBER', 409);
        }

        $link = MembershipVehicle::query()->create([
            'tenant_id' => $tenantId,
            'membership_id' => $membership->id,
            'vehicle_id' => $vehicle['id'],
            'plate' => $vehicle['plate'],
            'added_at' => now(),
            'added_by' => $actorUserId,
        ]);

        $this->vehicles->recordStayEvent($tenantId, $vehicle['id'], VehicleEventType::MembershipAdded, $actorUserId, [
            'membership_id' => (string) $membership->id,
            'code' => $membership->code,
            'plan' => $plan->name,
        ]);

        return $link;
    }
}
