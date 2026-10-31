<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Infrastructure\Persistence\ParkingZone;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\Eloquent\Collection;

final class ListParkingZones
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
    ) {}

    /**
     * A lot has a handful of zones, so they are returned without pagination.
     *
     * @return Collection<int, ParkingZone>
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_VIEW);

        $lot = $this->lookup->lot($tenantId, $lotId);

        return ParkingZone::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lot->id)
            ->withCount('spaces')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(200)
            ->get();
    }
}
