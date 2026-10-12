<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use Illuminate\Database\Eloquent\Collection;

final class ListTenantMembers
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * @return Collection<int, TenantMembership>
     */
    public function handle(string $actorUserId, string $tenantId): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::USERS_VIEW);

        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->with(['user', 'roles'])
            ->orderBy('created_at')
            ->get();
    }
}
