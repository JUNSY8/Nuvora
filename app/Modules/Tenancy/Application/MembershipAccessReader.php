<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Persistence\Role;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class MembershipAccessReader
{
    /**
     * @return list<ResolvedTenant>
     */
    public function listForUser(string $userId): array
    {
        return $this->query($userId)
            ->get()
            ->map(fn (TenantMembership $membership): ResolvedTenant => $this->map($membership))
            ->values()
            ->all();
    }

    public function find(string $userId, string $tenantId): ?ResolvedTenant
    {
        if (! Str::isUlid($tenantId)) {
            return null;
        }

        $membership = $this->query($userId)->where('tenant_id', $tenantId)->first();

        return $membership instanceof TenantMembership ? $this->map($membership) : null;
    }

    public function sole(string $userId): ?ResolvedTenant
    {
        $memberships = $this->listForUser($userId);

        return count($memberships) === 1 ? $memberships[0] : null;
    }

    private function query(string $userId): Builder
    {
        return TenantMembership::query()
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('tenant', function ($query): void {
                $query->where('status', TenantStatus::Active->value);
            })
            ->with(['tenant', 'roles.permissionGrants']);
    }

    private function map(TenantMembership $membership): ResolvedTenant
    {
        /** @var list<string> $roles */
        $roles = $membership->roles->pluck('key')->values()->all();

        /** @var list<string> $permissions */
        $permissions = $membership->roles
            ->flatMap(fn (Role $role) => $role->permissionGrants->pluck('permission'))
            ->unique()
            ->values()
            ->all();

        return new ResolvedTenant(
            id: (string) $membership->tenant->id,
            name: (string) $membership->tenant->name,
            slug: (string) $membership->tenant->slug,
            timezone: (string) $membership->tenant->timezone,
            roles: $roles,
            permissions: $permissions,
        );
    }
}
