<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\PermissionDenied;
use App\Modules\Tenancy\Domain\SystemRoles;

final class TenantAuthorizer
{
    public function __construct(private readonly MembershipAccessReader $memberships) {}

    public function assert(string $userId, string $tenantId, string $permission): void
    {
        $access = $this->memberships->find($userId, $tenantId);

        if ($access === null || ! in_array($permission, $access->permissions, true)) {
            throw new PermissionDenied;
        }
    }

    public function allows(string $userId, string $tenantId, string $permission): bool
    {
        $access = $this->memberships->find($userId, $tenantId);

        return $access !== null && in_array($permission, $access->permissions, true);
    }

    public function isOwner(string $userId, string $tenantId): bool
    {
        $access = $this->memberships->find($userId, $tenantId);

        return $access !== null && in_array(SystemRoles::OWNER, $access->roles, true);
    }
}
