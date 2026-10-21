<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;

final class ShowVehicle
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly VehicleLookup $lookup,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $vehicleId): Vehicle
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_VIEW);

        return $this->lookup->find($tenantId, $vehicleId)->load('activeLinks');
    }
}
