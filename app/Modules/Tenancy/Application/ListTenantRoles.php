<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Infrastructure\Persistence\Role;
use Illuminate\Database\Eloquent\Collection;

final class ListTenantRoles
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * @return Collection<int, Role>
     */
    public function handle(string $actorUserId, string $tenantId): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::ROLES_VIEW);

        return Role::query()
            ->where('tenant_id', $tenantId)
            ->with('permissionGrants')
            ->orderBy('name')
            ->get();
    }
}
