<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Domain\MembershipRules;
use App\Modules\Tenancy\Domain\MembershipRuleViolation;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Domain\SystemRoles;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;

final class ChangeMembershipStatus
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $membershipId, MembershipStatus $status): TenantMembership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::USERS_MANAGE);

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($membershipId)
            ->with('roles')
            ->firstOrFail();

        if ($status === MembershipStatus::Suspended) {
            $targetIsOwner = $membership->roles->contains('key', SystemRoles::OWNER);
            $reason = MembershipRules::suspensionBlockReason(
                isSelf: $membership->user_id === $actorUserId,
                targetIsOwner: $targetIsOwner,
                otherActiveOwners: $this->otherActiveOwners($tenantId, (string) $membership->id),
            );

            if ($reason !== null) {
                throw new MembershipRuleViolation($reason);
            }
        }

        $before = $membership->status->value;
        $membership->status = $status;
        $membership->save();

        $this->audit->record(
            action: 'membership.status_changed',
            resourceType: 'tenant_membership',
            resourceId: (string) $membership->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'before' => $before,
                'after' => $status->value,
            ],
        );

        return $membership->load(['user', 'roles']);
    }

    private function otherActiveOwners(string $tenantId, string $membershipId): int
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MembershipStatus::Active->value)
            ->whereKeyNot($membershipId)
            ->whereHas('roles', fn ($query) => $query->where('key', SystemRoles::OWNER))
            ->count();
    }
}
