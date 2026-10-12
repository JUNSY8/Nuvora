<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Domain\MembershipRules;
use App\Modules\Tenancy\Domain\MembershipRuleViolation;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Domain\SystemRoles;
use App\Modules\Tenancy\Infrastructure\Persistence\MembershipRole;
use App\Modules\Tenancy\Infrastructure\Persistence\Role;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SyncMembershipRoles
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<string>  $roleKeys
     */
    public function handle(string $actorUserId, string $tenantId, string $membershipId, array $roleKeys): TenantMembership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::ROLES_MANAGE);

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($membershipId)
            ->with('roles')
            ->firstOrFail();

        $roles = $this->rolesFor($tenantId, $roleKeys);
        $targetIsOwner = $membership->roles->contains('key', SystemRoles::OWNER);
        $resultIsOwner = $roles->contains('key', SystemRoles::OWNER);
        $reason = MembershipRules::roleChangeBlockReason(
            actorIsOwner: $this->authorizer->isOwner($actorUserId, $tenantId),
            targetIsOwner: $targetIsOwner,
            resultIsOwner: $resultIsOwner,
            otherActiveOwners: $this->otherActiveOwners($tenantId, (string) $membership->id),
        );

        if ($reason !== null) {
            throw new MembershipRuleViolation($reason);
        }

        DB::transaction(function () use ($membership, $roles): void {
            MembershipRole::query()->where('membership_id', $membership->id)->delete();

            foreach ($roles as $role) {
                MembershipRole::query()->create([
                    'membership_id' => $membership->id,
                    'role_id' => $role->id,
                ]);
            }
        });

        $this->audit->record(
            action: 'membership.roles_synced',
            resourceType: 'tenant_membership',
            resourceId: (string) $membership->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'roles' => $roles->pluck('key')->values()->all(),
            ],
        );

        return $membership->load(['user', 'roles']);
    }

    /**
     * @param  list<string>  $roleKeys
     * @return Collection<int, Role>
     */
    private function rolesFor(string $tenantId, array $roleKeys): Collection
    {
        $roleKeys = array_values(array_unique($roleKeys));

        if ($roleKeys === []) {
            throw ValidationException::withMessages([
                'roles' => 'Selecciona al menos un rol.',
            ]);
        }

        $roles = Role::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', $roleKeys)
            ->get();

        if ($roles->count() !== count($roleKeys)) {
            throw ValidationException::withMessages([
                'roles' => 'Uno de los roles no pertenece a esta empresa.',
            ]);
        }

        return $roles;
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
