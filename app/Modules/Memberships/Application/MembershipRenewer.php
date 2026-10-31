<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves memberships along with the calendar, in each tenant's local day:
 *
 * - an active membership with automatic renewal whose period ended opens
 *   the next period (recorded as due);
 * - any other membership whose period and grace days ended expires.
 *
 * The scheduler runs it every hour; running it twice changes nothing.
 */
final class MembershipRenewer
{
    public function __construct(
        private readonly MembershipCalendar $calendar,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function run(): int
    {
        $changed = 0;
        $tenants = Membership::query()
            ->whereIn('status', MembershipStatus::holdingValues())
            ->where('current_period_end', '<', now()->addDay()->toDateString())
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenants as $tenantId) {
            $today = $this->calendar->today((string) $tenantId);
            $due = Membership::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', MembershipStatus::holdingValues())
                ->where('current_period_end', '<', $today->toDateString())
                ->pluck('id');

            foreach ($due as $membershipId) {
                $changed += DB::transaction(fn (): int => $this->settle((string) $tenantId, (string) $membershipId, $today));
            }
        }

        return $changed;
    }

    private function settle(string $tenantId, string $membershipId, DateTimeImmutable $today): int
    {
        $membership = Membership::query()->where('tenant_id', $tenantId)->whereKey($membershipId)->lockForUpdate()->first();

        if ($membership === null || ! in_array($membership->status->value, MembershipStatus::holdingValues(), true) || $membership->current_period_end->format('Y-m-d') >= $today->format('Y-m-d')) {
            return 0;
        }

        if ($membership->status === MembershipStatus::Active && $membership->auto_renew) {
            $period = $this->calendar->openNextPeriod($membership, $today, 'automatic', null);
            $this->audit->record('membership.renewed', 'membership', $membershipId, $tenantId, null, [
                'code' => $membership->code,
                'period' => $period->sequence,
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
                'automatic' => true,
            ]);

            return 1;
        }

        $until = MembershipCalendar::coveredUntil($membership);

        if ($until->format('Y-m-d') >= $today->format('Y-m-d')) {
            return 0;
        }

        $membership->fill(['status' => MembershipStatus::Expired, 'ended_on' => $until])->save();
        $this->audit->record('membership.expired', 'membership', $membershipId, $tenantId, null, ['code' => $membership->code, 'ended_on' => $until->format('Y-m-d')]);

        return 1;
    }
}
