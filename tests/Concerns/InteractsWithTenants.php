<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Tenancy\Application\AddTenantMember;
use App\Modules\Tenancy\Application\CreateTenant;
use App\Modules\Tenancy\Infrastructure\Persistence\Tenant;

trait InteractsWithTenants
{
    /**
     * @return array{0: User, 1: Tenant}
     */
    protected function createTenantOwner(string $name = 'Parqueadero Central'): array
    {
        $user = User::factory()->create();
        $tenant = app(CreateTenant::class)->handle($user, $name, 'America/Bogota');

        return [$user, $tenant];
    }

    protected function addMember(User $owner, Tenant $tenant, User $member, string $role): void
    {
        app(AddTenantMember::class)->handle(
            actorUserId: (string) $owner->id,
            tenantId: (string) $tenant->id,
            email: (string) $member->email,
            roleKeys: [$role],
        );
    }
}
