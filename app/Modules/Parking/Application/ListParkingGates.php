<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Infrastructure\Persistence\ParkingGate;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\Eloquent\Collection;

final class ListParkingGates
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
    ) {}

    /**
     * @return Collection<int, ParkingGate>
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_VIEW);

        $lot = $this->lookup->lot($tenantId, $lotId);

        return ParkingGate::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lot->id)
            ->orderBy('code')
            ->limit(200)
            ->get();
    }
}
