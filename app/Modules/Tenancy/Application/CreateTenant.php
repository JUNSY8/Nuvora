<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Identity\Domain\EmailNotVerified;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\SystemRoles;
use App\Modules\Tenancy\Domain\TenantSlug;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Persistence\MembershipRole;
use App\Modules\Tenancy\Infrastructure\Persistence\Role;
use App\Modules\Tenancy\Infrastructure\Persistence\RolePermission;
use App\Modules\Tenancy\Infrastructure\Persistence\Tenant;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use App\Modules\Tenancy\Infrastructure\TenantDatabaseSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateTenant
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly TenantDatabaseSession $database,
    ) {}

    public function handle(User $user, string $name, string $timezone): Tenant
    {
        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerified;
        }

        $name = trim($name);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $name, $timezone): Tenant {
                    $tenant = Tenant::query()->create([
                        'name' => $name,
                        'slug' => $this->uniqueSlug($name),
                        'status' => TenantStatus::Active,
                        'timezone' => $timezone,
                    ]);

                    $this->database->apply((string) $tenant->id);

                    $ownerRoleId = null;

                    foreach (SystemRoles::definitions() as $key => $definition) {
                        $role = Role::query()->create([
                            'tenant_id' => $tenant->id,
                            'key' => $key,
                            'name' => $definition['name'],
                            'is_system' => true,
                        ]);

                        foreach ($definition['permissions'] as $permission) {
                            RolePermission::query()->create([
                                'role_id' => $role->id,
                                'permission' => $permission,
                            ]);
                        }

                        if ($key === SystemRoles::OWNER) {
                            $ownerRoleId = (string) $role->id;
                        }
                    }

                    if ($ownerRoleId === null) {
                        throw new RuntimeException('La empresa no tiene rol de propietario.');
                    }

                    $membership = TenantMembership::query()->create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'status' => MembershipStatus::Active,
                    ]);

                    MembershipRole::query()->create([
                        'membership_id' => $membership->id,
                        'role_id' => $ownerRoleId,
                    ]);

                    $this->audit->record(
                        action: 'tenant.created',
                        resourceType: 'tenant',
                        resourceId: (string) $tenant->id,
                        tenantId: (string) $tenant->id,
                        actorUserId: (string) $user->id,
                        properties: [
                            'name' => $tenant->name,
                            'slug' => $tenant->slug,
                            'timezone' => $tenant->timezone,
                        ],
                    );

                    return $tenant;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('No fue posible crear la empresa.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = TenantSlug::fromName($name);
        $slug = $base;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;

            if ($suffix > 50) {
                throw new RuntimeException('No fue posible asignar un identificador único a la empresa.');
            }
        }

        return $slug;
    }
}
