<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Memberships\Domain\AccessRules;
use App\Modules\Memberships\Domain\MembershipCode;
use App\Modules\Memberships\Domain\MembershipRuleViolation;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Domain\PlanStatus;
use App\Modules\Memberships\Infrastructure\MembershipLock;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sells a membership: a customer, a plan, the vehicles it covers and,
 * optionally, a space assigned to it. The plan's price and period are
 * copied, the first period is recorded as due and the plan's capacity is
 * checked under a lock, so two cashiers cannot sell its last place twice.
 */
final class SellMembership
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CustomerDirectory $customers,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly MembershipVehicles $vehicles,
        private readonly MembershipCalendar $calendar,
        private readonly MembershipLock $lock,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, SubscriptionData $data): Membership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_MANAGE);

        $plan = MembershipPlans::find($tenantId, $data->planId);

        if ($plan->status !== PlanStatus::Active) {
            throw new MembershipRuleViolation("El plan {$plan->name} ya no se vende.", 'PLAN_INACTIVE');
        }

        if (! $this->customers->isActive($tenantId, $data->customerId)) {
            throw ValidationException::withMessages(['customer_id' => 'Selecciona un cliente activo de esta empresa.']);
        }

        if ($data->vehicles === [] || count($data->vehicles) > $plan->max_vehicles) {
            throw new MembershipRuleViolation("El plan {$plan->name} cubre entre 1 y {$plan->max_vehicles} vehículo(s).", 'TOO_MANY_VEHICLES');
        }

        $today = $this->calendar->today($tenantId);
        $startsOn = CarbonImmutable::createFromFormat('!Y-m-d', $data->startsOn, $today->getTimezone());

        if (! $startsOn instanceof CarbonImmutable || $startsOn < $today->subDays(31) || $startsOn > $today->addDays(90)) {
            throw ValidationException::withMessages(['starts_on' => 'La mensualidad debe empezar entre 31 días atrás y 90 días adelante.']);
        }

        $space = $this->assignedSpace($tenantId, $data, MembershipPlans::rules($plan));

        try {
            $membership = DB::transaction(function () use ($actorUserId, $tenantId, $data, $plan, $startsOn, $space): Membership {
                $this->lock->forPlan((string) $plan->id);

                if ($plan->capacity !== null) {
                    $sold = Membership::query()->where('tenant_id', $tenantId)->where('plan_id', $plan->id)->whereIn('status', MembershipStatus::holdingValues())->count();

                    if ($sold >= $plan->capacity) {
                        throw new MembershipRuleViolation("El plan {$plan->name} no tiene cupos: {$sold} de {$plan->capacity} vendidos.", 'PLAN_FULL', 409);
                    }
                }

                if ($space !== null && Membership::query()->where('tenant_id', $tenantId)->where('assigned_space_id', $space['id'])->whereIn('status', MembershipStatus::holdingValues())->exists()) {
                    throw new MembershipRuleViolation("El espacio {$space['code']} ya está asignado a otra mensualidad.", 'SPACE_ALREADY_ASSIGNED', 409);
                }

                [$start, $end] = $plan->billing_period->period($startsOn, 0);

                $membership = Membership::query()->create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'customer_id' => $data->customerId,
                    'code' => $this->freeCode($tenantId),
                    'status' => MembershipStatus::Active,
                    'billing_period' => $plan->billing_period,
                    'price_amount' => $plan->price_amount,
                    'currency' => $plan->currency,
                    'grace_days' => $plan->grace_days,
                    'starts_on' => $start,
                    'anchor_on' => $start,
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                    'periods_count' => 1,
                    'auto_renew' => $data->autoRenew,
                    'assigned_lot_id' => $space === null ? null : $data->assignedLotId,
                    'assigned_space_id' => $space['id'] ?? null,
                    'assigned_space_code' => $space['code'] ?? null,
                    'notes' => $data->notes,
                    'created_by' => $actorUserId,
                ]);

                $this->calendar->record($membership, $start, $end, 'subscription', $actorUserId);

                foreach ($data->vehicles as $vehicle) {
                    $this->vehicles->join($membership, $plan, $actorUserId, $vehicle['plate'], $vehicle['country'], $vehicle['type']);
                }

                return $membership;
            });
        } catch (UniqueConstraintViolationException) {
            throw new MembershipRuleViolation('Otra venta tomó el mismo vehículo o espacio al mismo tiempo. Intenta de nuevo.', 'MEMBERSHIP_CONFLICT', 409);
        }

        $this->audit->record(
            action: 'membership.created',
            resourceType: 'membership',
            resourceId: (string) $membership->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'code' => $membership->code,
                'plan' => $plan->code,
                'customer_id' => $data->customerId,
                'price_amount' => $membership->price_amount,
                'starts_on' => $membership->starts_on->format('Y-m-d'),
                'assigned_space_code' => $membership->assigned_space_code,
            ],
        );

        return $membership;
    }

    /**
     * @return array{id: string, code: string}|null
     */
    private function assignedSpace(string $tenantId, SubscriptionData $data, AccessRules $rules): ?array
    {
        if ($data->assignedSpaceId === null || $data->assignedLotId === null) {
            return null;
        }

        $space = $this->parking->space($tenantId, $data->assignedLotId, $data->assignedSpaceId)
            ?? throw ValidationException::withMessages(['assigned_space_id' => 'El espacio no pertenece a ese parqueadero.']);

        if (! $space['is_assignable']) {
            throw new MembershipRuleViolation("El espacio {$space['code']} no está marcado como asignable a mensualidades.", 'SPACE_NOT_ASSIGNABLE');
        }

        if (($rules->lotIds !== [] && ! in_array($data->assignedLotId, $rules->lotIds, true)) || ! $rules->allowsZone($space['zone_id'])) {
            throw new MembershipRuleViolation("El espacio {$space['code']} está fuera de los parqueaderos o zonas del plan.", 'SPACE_OUTSIDE_PLAN');
        }

        return ['id' => $space['id'], 'code' => $space['code']];
    }

    private function freeCode(string $tenantId): string
    {
        do {
            $code = MembershipCode::generate();
        } while (Membership::query()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}
