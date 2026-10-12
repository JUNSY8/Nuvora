<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Identity\Infrastructure\Persistence\User;
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

final class AddTenantMember
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<string>  $roleKeys
     */
    public function handle(string $actorUserId, string $tenantId, string $email, array $roleKeys): TenantMembership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::USERS_MANAGE);

        $email = mb_strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'No existe una cuenta con ese correo.',
            ]);
        }

        if (TenantMembership::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Esa persona ya pertenece a la empresa.',
            ]);
        }

        $roles = $this->rolesFor($tenantId, $roleKeys);
        $this->guardOwnerAssignment($actorUserId, $tenantId, $roleKeys, false);

        return DB::transaction(function () use ($actorUserId, $tenantId, $user, $roles): TenantMembership {
            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'status' => MembershipStatus::Active,
            ]);

            foreach ($roles as $role) {
                MembershipRole::query()->create([
                    'membership_id' => $membership->id,
                    'role_id' => $role->id,
                ]);
            }

            $this->audit->record(
                action: 'membership.created',
                resourceType: 'tenant_membership',
                resourceId: (string) $membership->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: [
                    'user_id' => (string) $user->id,
                    'roles' => $roles->pluck('key')->values()->all(),
                ],
            );

            return $membership->load(['user', 'roles']);
        });
    }

    /**
     * @param  list<string>  $roleKeys
     */
    public function guardOwnerAssignment(string $actorUserId, string $tenantId, array $roleKeys, bool $targetIsOwner): void
    {
        $resultIsOwner = in_array(SystemRoles::OWNER, $roleKeys, true);
        $reason = MembershipRules::roleChangeBlockReason(
            actorIsOwner: $this->authorizer->isOwner($actorUserId, $tenantId),
            targetIsOwner: $targetIsOwner,
            resultIsOwner: $resultIsOwner,
            otherActiveOwners: 1,
        );

        if ($reason !== null && str_contains($reason, 'propietario puede cambiar')) {
            throw new MembershipRuleViolation($reason);
        }
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
}
