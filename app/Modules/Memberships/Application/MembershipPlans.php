<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Memberships\Domain\AccessRules;
use App\Modules\Memberships\Domain\MembershipRuleViolation;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Domain\PlanCode;
use App\Modules\Memberships\Domain\PlanStatus;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPlan;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Tariffs\Domain\TimeWindow;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plans are the catalog of memberships. Editing a plan changes its access
 * rules for every member at once, but not the price or period already sold:
 * those are copied into each membership when it is sold.
 */
final class MembershipPlans
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @return Collection<int, MembershipPlan>
     */
    public function list(string $actorUserId, string $tenantId, ?PlanStatus $status): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_VIEW);

        return MembershipPlan::query()
            ->where('tenant_id', $tenantId)
            ->when($status !== null, fn ($query) => $query->where('status', $status?->value))
            ->orderBy('status')
            ->orderBy('name')
            ->get();
    }

    /**
     * Memberships in force per plan, against each plan's capacity.
     *
     * @return array<string, int>
     */
    public function sold(string $tenantId): array
    {
        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', MembershipStatus::holdingValues())
            ->selectRaw('plan_id, count(*) as total')
            ->groupBy('plan_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->plan_id => (int) $row->total])
            ->all();
    }

    public function save(string $actorUserId, string $tenantId, ?string $planId, PlanData $data): MembershipPlan
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_MANAGE);

        $plan = $planId === null ? null : self::find($tenantId, $planId);
        $attributes = $this->attributes($tenantId, $data);

        $taken = MembershipPlan::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $attributes['code'])
            ->when($plan !== null, fn ($query) => $query->whereKeyNot($plan?->id))
            ->exists();

        if ($taken) {
            throw self::codeTaken();
        }

        $before = $plan?->only(array_keys($attributes));

        try {
            $plan = DB::transaction(function () use ($plan, $attributes, $tenantId, $actorUserId): MembershipPlan {
                if ($plan !== null) {
                    $plan->fill($attributes)->save();

                    return $plan;
                }

                return MembershipPlan::query()->create([...$attributes, 'tenant_id' => $tenantId, 'status' => PlanStatus::Active, 'created_by' => $actorUserId]);
            });
        } catch (UniqueConstraintViolationException) {
            throw self::codeTaken();
        }

        $this->audit->record(
            action: $before === null ? 'membership_plan.created' : 'membership_plan.updated',
            resourceType: 'membership_plan',
            resourceId: (string) $plan->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: $before === null ? ['code' => $plan->code] : ['code' => $plan->code, 'before' => self::plain($before), 'after' => self::plain($plan->only(array_keys($attributes)))],
        );

        return $plan;
    }

    public function changeStatus(string $actorUserId, string $tenantId, string $planId, PlanStatus $status): MembershipPlan
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_MANAGE);

        $plan = self::find($tenantId, $planId);
        $from = $plan->status;

        if ($from !== $status) {
            $plan->fill(['status' => $status])->save();

            $this->audit->record(
                action: 'membership_plan.status_changed',
                resourceType: 'membership_plan',
                resourceId: (string) $plan->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['code' => $plan->code, 'from' => $from->value, 'to' => $status->value],
            );
        }

        return $plan;
    }

    public static function find(string $tenantId, string $planId, bool $lock = false): MembershipPlan
    {
        $query = MembershipPlan::query()->where('tenant_id', $tenantId)->whereKey($planId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public static function rules(MembershipPlan $plan): AccessRules
    {
        return new AccessRules(
            lotIds: array_values(array_map('strval', $plan->parking_lot_ids)),
            zoneIds: array_values(array_map('strval', $plan->zone_ids)),
            vehicleTypes: array_values(array_filter(array_map(fn (mixed $type): ?VehicleType => is_string($type) ? VehicleType::tryFrom($type) : null, $plan->vehicle_types))),
            windows: array_map(fn (array $window): TimeWindow => TimeWindow::fromArray($window), $plan->windows),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(string $tenantId, PlanData $data): array
    {
        $currency = strtoupper($data->currency);

        if ($data->priceAmount < 0 || ! Currency::isChargeable($data->priceAmount, $currency)) {
            throw new MembershipRuleViolation("El precio no se puede cobrar en {$currency}: usa unidades enteras de la moneda.", 'INVALID_PLAN');
        }

        $lotIds = array_values(array_unique($data->lotIds));

        foreach ($lotIds as $lotId) {
            if (! $this->parking->lotExists($tenantId, $lotId)) {
                throw ValidationException::withMessages(['parking_lot_ids' => 'Uno de los parqueaderos no pertenece a esta empresa.']);
            }
        }

        $zoneIds = array_values(array_unique($data->zoneIds));
        $zones = $this->parking->zoneSummaries($tenantId, $zoneIds);

        if (count($zones) !== count($zoneIds)) {
            throw ValidationException::withMessages(['zone_ids' => 'Una de las zonas no pertenece a esta empresa.']);
        }

        $windows = array_map(
            fn (mixed $window): array => TimeWindow::fromArray(is_array($window) ? $window : [])->toArray(),
            array_values($data->windows),
        );

        return [
            'code' => PlanCode::normalize($data->code),
            'name' => trim($data->name),
            'category' => $data->category,
            'billing_period' => $data->billingPeriod,
            'price_amount' => $data->priceAmount,
            'currency' => $currency,
            'grace_days' => $data->graceDays,
            'max_vehicles' => $data->maxVehicles,
            'capacity' => $data->capacity,
            'parking_lot_ids' => $lotIds,
            'zone_ids' => $zoneIds,
            'vehicle_types' => array_values(array_map(
                fn (VehicleType $type): string => $type->value,
                array_filter(VehicleType::cases(), fn (VehicleType $type): bool => in_array($type, $data->vehicleTypes, true)),
            )),
            'windows' => $windows,
            'description' => $data->description,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function plain(array $values): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, $values);
    }

    private static function codeTaken(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'Ya existe un plan con ese código en la empresa.']);
    }
}
