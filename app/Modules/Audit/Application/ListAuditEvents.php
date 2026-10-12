<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Persistence\AuditLog;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAuditEvents
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function handle(string $actorUserId, string $tenantId, int $page): LengthAwarePaginator
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::AUDIT_VIEW);

        return AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: 20, page: $page);
    }
}
