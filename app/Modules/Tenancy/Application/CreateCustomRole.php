<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Domain\SystemRoles;
use App\Modules\Tenancy\Domain\TenantSlug;
use App\Modules\Tenancy\Infrastructure\Persistence\Role;
use App\Modules\Tenancy\Infrastructure\Persistence\RolePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateCustomRole
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function handle(string $actorUserId, string $tenantId, string $name, array $permissions): Role
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::ROLES_MANAGE);

        $permissions = array_values(array_unique($permissions));
        $unknown = array_diff($permissions, Permission::all());

        if ($permissions === [] || $unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Selecciona permisos válidos del catálogo.',
            ]);
        }

        $key = $this->uniqueKey($tenantId, TenantSlug::fromName($name));

        $role = DB::transaction(function () use ($tenantId, $key, $name, $permissions): Role {
            $role = Role::query()->create([
                'tenant_id' => $tenantId,
                'key' => $key,
                'name' => trim($name),
                'is_system' => false,
            ]);

            foreach ($permissions as $permission) {
                RolePermission::query()->create([
                    'role_id' => $role->id,
                    'permission' => $permission,
                ]);
            }

            return $role->load('permissionGrants');
        });

        $this->audit->record(
            action: 'role.created',
            resourceType: 'role',
            resourceId: (string) $role->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'key' => $role->key,
                'permissions' => $permissions,
            ],
        );

        return $role;
    }

    private function uniqueKey(string $tenantId, string $base): string
    {
        $base = str_replace('-', '_', $base);

        if (in_array($base, SystemRoles::keys(), true)) {
            $base .= '_custom';
        }

        $key = $base;
        $suffix = 2;

        while (Role::query()->where('tenant_id', $tenantId)->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }
}
