<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Infrastructure\Persistence\Tenant;

final class UpdateTenant
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $name, string $timezone): Tenant
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TENANTS_UPDATE);

        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $before = ['name' => $tenant->name, 'timezone' => $tenant->timezone];

        $tenant->fill([
            'name' => trim($name),
            'timezone' => $timezone,
        ])->save();

        $this->audit->record(
            action: 'tenant.updated',
            resourceType: 'tenant',
            resourceId: (string) $tenant->id,
            tenantId: (string) $tenant->id,
            actorUserId: $actorUserId,
            properties: [
                'before' => $before,
                'after' => ['name' => $tenant->name, 'timezone' => $tenant->timezone],
            ],
        );

        return $tenant;
    }
}
