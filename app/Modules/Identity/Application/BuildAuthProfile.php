<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Tenancy\Application\MembershipAccessReader;
use App\Modules\Tenancy\Application\ResolvedTenant;

final class BuildAuthProfile
{
    public function __construct(private readonly MembershipAccessReader $memberships) {}

    /**
     * @return array{user: array<string, mixed>, tenants: list<array<string, mixed>>, current_tenant: array<string, mixed>|null}
     */
    public function handle(User $user, ?string $currentTenantId): array
    {
        $tenants = array_map(
            fn (ResolvedTenant $tenant): array => $this->tenantPayload($tenant),
            $this->memberships->listForUser((string) $user->id),
        );

        $current = null;

        foreach ($tenants as $tenant) {
            if ($tenant['id'] === $currentTenantId) {
                $current = $tenant;
                break;
            }
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'tenants' => $tenants,
            'current_tenant' => $current,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantPayload(ResolvedTenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'timezone' => $tenant->timezone,
            'roles' => $tenant->roles,
            'permissions' => $tenant->permissions,
        ];
    }
}
