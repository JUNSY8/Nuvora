<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Memberships\Domain\MembershipRuleViolation;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Manual lifecycle of a membership: renew one period, suspend (for example,
 * unpaid), reactivate, cancel, and turn automatic renewal on or off. Every
 * step locks the membership and is audited.
 */
final class ChangeMembership
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly MembershipLookup $lookup,
        private readonly MembershipCalendar $calendar,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Opens one more period to charge. A retry with the same idempotency key
     * returns the membership as the first attempt left it; without a key,
     * a membership already renewed ahead is not renewed again.
     */
    public function renew(string $actorUserId, string $tenantId, string $membershipId, ?string $idempotencyKey = null): Membership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_MANAGE);

        [$membership, $period] = DB::transaction(function () use ($actorUserId, $tenantId, $membershipId, $idempotencyKey): array {
            $membership = $this->lookup->find($tenantId, $membershipId, lock: true);

            if ($idempotencyKey !== null && $membership->periods()->where('idempotency_key', $idempotencyKey)->exists()) {
                return [$membership, null];
            }

            if ($membership->status === MembershipStatus::Cancelled) {
                throw self::notChangeable($membership);
            }

            $today = $this->calendar->today($tenantId);
            $latest = $membership->periods()->first();

            // At most one period ahead of the one in course.
            if ($latest !== null && $latest->starts_on->format('Y-m-d') > $today->format('Y-m-d')) {
                throw new MembershipRuleViolation(
                    "La mensualidad {$membership->code} ya está renovada hasta el {$latest->ends_on->format('Y-m-d')}.",
                    'ALREADY_RENEWED',
                    409,
                );
            }

            return [$membership, $this->calendar->openNextPeriod($membership, $today, 'renewal', $actorUserId, $idempotencyKey)];
        });

        if ($period !== null) {
            $this->audit->record('membership.renewed', 'membership', (string) $membership->id, $tenantId, $actorUserId, [
                'code' => $membership->code,
                'period' => $period->sequence,
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
            ]);
        }

        return $membership;
    }

    public function suspend(string $actorUserId, string $tenantId, string $membershipId, string $reason): Membership
    {
        return $this->change($actorUserId, $tenantId, $membershipId, 'membership.suspended', function (Membership $membership) use ($reason): array {
            $this->transition($membership, MembershipStatus::Suspended);
            $membership->fill(['status' => MembershipStatus::Suspended, 'status_reason' => trim($reason)])->save();

            return ['reason' => trim($reason)];
        });
    }

    public function reactivate(string $actorUserId, string $tenantId, string $membershipId): Membership
    {
        return $this->change($actorUserId, $tenantId, $membershipId, 'membership.reactivated', function (Membership $membership): array {
            if ($membership->status !== MembershipStatus::Suspended) {
                throw self::notChangeable($membership);
            }

            $membership->fill(['status' => MembershipStatus::Active, 'status_reason' => null])->save();

            return [];
        });
    }

    public function cancel(string $actorUserId, string $tenantId, string $membershipId, string $reason): Membership
    {
        return $this->change($actorUserId, $tenantId, $membershipId, 'membership.cancelled', function (Membership $membership) use ($tenantId, $reason): array {
            $this->transition($membership, MembershipStatus::Cancelled);
            $membership->fill([
                'status' => MembershipStatus::Cancelled,
                'status_reason' => trim($reason),
                'ended_on' => $this->calendar->today($tenantId),
                'auto_renew' => false,
            ])->save();

            return ['reason' => trim($reason)];
        });
    }

    public function setAutoRenew(string $actorUserId, string $tenantId, string $membershipId, bool $autoRenew): Membership
    {
        return $this->change($actorUserId, $tenantId, $membershipId, 'membership.auto_renew_changed', function (Membership $membership) use ($autoRenew): array {
            if ($membership->status === MembershipStatus::Cancelled) {
                throw self::notChangeable($membership);
            }

            $membership->fill(['auto_renew' => $autoRenew])->save();

            return ['auto_renew' => $autoRenew];
        });
    }

    /**
     * @param  callable(Membership): array<string, mixed>  $apply
     */
    private function change(string $actorUserId, string $tenantId, string $membershipId, string $action, callable $apply): Membership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_MANAGE);

        [$membership, $properties] = DB::transaction(function () use ($tenantId, $membershipId, $apply): array {
            $membership = $this->lookup->find($tenantId, $membershipId, lock: true);

            return [$membership, $apply($membership)];
        });

        $this->audit->record($action, 'membership', (string) $membership->id, $tenantId, $actorUserId, ['code' => $membership->code, ...$properties]);

        return $membership;
    }

    private function transition(Membership $membership, MembershipStatus $target): void
    {
        if (! $membership->status->canTransitionTo($target)) {
            throw self::notChangeable($membership);
        }
    }

    private static function notChangeable(Membership $membership): MembershipRuleViolation
    {
        $state = match ($membership->status) {
            MembershipStatus::Active => 'está activa',
            MembershipStatus::Suspended => 'está suspendida',
            MembershipStatus::Expired => 'está vencida',
            MembershipStatus::Cancelled => 'fue cancelada',
        };

        return new MembershipRuleViolation("La mensualidad {$membership->code} {$state}.", 'MEMBERSHIP_NOT_CHANGEABLE');
    }
}
