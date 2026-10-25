<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Memberships\Domain\AccessDecision;
use App\Modules\Memberships\Domain\AccessRules;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPeriod;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipVehicle;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Public contract of the Memberships module for parking sessions: the
 * access policy of an arriving vehicle. Sessions authorize the caller; this
 * contract does not check membership permissions.
 */
final class MembershipDirectory
{
    /** A recently ended membership is still reported, to tell the operator why it does not apply. */
    private const RECENTLY_ENDED_DAYS = 31;

    /**
     * The membership of an arriving vehicle and whether it covers this
     * arrival, or null when the vehicle has none.
     *
     * @return array{membership: array{id: string, code: string, plan: string, customer_id: string, assigned_lot_id: string|null, assigned_space_id: string|null, zone_ids: list<string>}, decision: AccessDecision}|null
     */
    public function accessFor(string $tenantId, string $lotId, string $timezone, string $vehicleId, VehicleType $vehicleType, DateTimeImmutable $at): ?array
    {
        $arrival = $at->setTimezone(new DateTimeZone($timezone));
        $recent = $arrival->modify('-'.self::RECENTLY_ENDED_DAYS.' days')->format('Y-m-d');

        $membership = Membership::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', MembershipVehicle::query()->where('tenant_id', $tenantId)->where('vehicle_id', $vehicleId)->whereNull('removed_at')->select('membership_id'))
            ->where(fn ($query) => $query->whereIn('status', MembershipStatus::holdingValues())->orWhere('ended_on', '>=', $recent))
            ->with('plan')
            // Memberships in force first, then the most recently ended.
            ->orderByRaw("case when status in ('active', 'suspended') then 0 else 1 end")
            ->orderByDesc('current_period_end')
            ->first();

        if ($membership === null) {
            return null;
        }

        $rules = MembershipPlans::rules($membership->plan);
        $decision = $rules->evaluate(
            $membership->status,
            $membership->current_period_start,
            MembershipCalendar::coveredUntil($membership),
            $lotId,
            $vehicleType,
            $arrival,
        );
        // The period of the arrival day; during the grace after the last period, the last one.
        $period = MembershipPeriod::query()
            ->where('membership_id', $membership->id)
            ->whereDate('starts_on', '<=', $arrival->format('Y-m-d'))
            ->where('status', '!=', 'void')
            ->orderByDesc('starts_on')
            ->first();

        if ($period !== null) {
            $decision = AccessRules::requirePayment($decision, $period->status, $period->starts_on, $period->ends_on, $membership->grace_days, $arrival);
        }

        return [
            'membership' => [
                'id' => (string) $membership->id,
                'code' => $membership->code,
                'plan' => $membership->plan->name,
                'customer_id' => (string) $membership->customer_id,
                'assigned_lot_id' => $membership->assigned_lot_id === null ? null : (string) $membership->assigned_lot_id,
                'assigned_space_id' => $membership->assigned_space_id === null ? null : (string) $membership->assigned_space_id,
                'zone_ids' => $rules->zoneIds,
            ],
            'decision' => $decision,
        ];
    }

    /**
     * @param  list<string>  $membershipIds
     * @return array<string, array{id: string, code: string, plan: string}>
     */
    public function summaries(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $membershipIds)
            ->with('plan:id,name')
            ->get(['id', 'code', 'plan_id'])
            ->mapWithKeys(fn (Membership $membership): array => [
                (string) $membership->id => ['id' => (string) $membership->id, 'code' => $membership->code, 'plan' => $membership->plan->name],
            ])
            ->all();
    }
}
