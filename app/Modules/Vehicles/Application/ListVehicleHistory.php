<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Infrastructure\Persistence\VehicleEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListVehicleHistory
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly VehicleLookup $lookup,
    ) {}

    /**
     * @return LengthAwarePaginator<int, VehicleEvent>
     */
    public function handle(string $actorUserId, string $tenantId, string $vehicleId, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_VIEW);

        $vehicle = $this->lookup->find($tenantId, $vehicleId);

        return VehicleEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('vehicle_id', $vehicle->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }
}
