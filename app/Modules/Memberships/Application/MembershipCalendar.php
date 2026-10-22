<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPeriod;
use App\Modules\Tenancy\Application\TenantDirectory;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Days and periods of memberships. "Today" is the tenant's local day, never
 * the server's. Each period is recorded with what it costs so payments
 * (0.9) can collect it.
 */
final class MembershipCalendar
{
    public function __construct(private readonly TenantDirectory $tenants) {}

    /**
     * The tenant's local date, as midnight UTC like every date column, so
     * dates compare as dates and never as instants of different timezones.
     */
    public function today(string $tenantId): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($this->tenants->timezone($tenantId))->toDateString(), 'UTC');
    }

    /**
     * Opens the next period of the membership. An expired membership (or
     * one whose next period would already be over) starts a new cycle today
     * instead of charging for days it could not use.
     */
    public function openNextPeriod(Membership $membership, DateTimeImmutable $today, string $origin, ?string $actorUserId): MembershipPeriod
    {
        $anchor = $membership->anchor_on;
        $index = $membership->periods_count;
        [$start, $end] = $membership->billing_period->period($anchor, $index);

        if ($membership->status === MembershipStatus::Expired || $end->format('Y-m-d') < $today->format('Y-m-d')) {
            $anchor = CarbonImmutable::instance($today);
            $index = 0;
            [$start, $end] = $membership->billing_period->period($anchor, 0);
        }

        // Renewing adds the next period but does not lift a suspension.
        $suspended = $membership->status === MembershipStatus::Suspended;
        // Renewed early, the days still paid stay covered: the span only grows.
        $stillCovered = $index > 0 && $membership->current_period_end->format('Y-m-d') >= $today->format('Y-m-d');

        $membership->fill([
            'status' => $suspended ? MembershipStatus::Suspended : MembershipStatus::Active,
            'anchor_on' => $anchor,
            'current_period_start' => $stillCovered ? $membership->current_period_start : $start,
            'current_period_end' => $end,
            'periods_count' => $index + 1,
            'ended_on' => null,
            'status_reason' => $suspended ? $membership->status_reason : null,
        ])->save();

        return $this->record($membership, $start, $end, $origin, $actorUserId);
    }

    public function record(Membership $membership, DateTimeImmutable $start, DateTimeImmutable $end, string $origin, ?string $actorUserId): MembershipPeriod
    {
        $sequence = (int) MembershipPeriod::query()->where('membership_id', $membership->id)->max('sequence') + 1;

        return MembershipPeriod::query()->create([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'sequence' => $sequence,
            'starts_on' => $start,
            'ends_on' => $end,
            'amount' => $membership->price_amount,
            'currency' => $membership->currency,
            'status' => 'due',
            'origin' => $origin,
            'created_by' => $actorUserId,
        ]);
    }

    /**
     * Last local day a membership covers: its period plus the grace days.
     */
    public static function coveredUntil(Membership $membership): CarbonImmutable
    {
        return CarbonImmutable::instance($membership->current_period_end)->addDays($membership->grace_days);
    }
}
