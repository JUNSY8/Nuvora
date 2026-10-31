<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class ShowParkingSession
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly SessionLookup $lookup,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $sessionId): ParkingSession
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_VIEW);

        return $this->lookup->find($tenantId, $sessionId)->load(['openedBy:id,name', 'closedBy:id,name']);
    }
}
