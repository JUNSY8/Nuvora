<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\Occupancy;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class ShowParkingLot
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
        private readonly ParkingOccupancyReader $occupancy,
    ) {}

    /**
     * @return array{lot: ParkingLot, occupancy: Occupancy}
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_VIEW);

        $lot = $this->lookup->lot($tenantId, $lotId);
        $lot->load('operatingHours')->loadCount(['zones', 'gates']);

        return [
            'lot' => $lot,
            'occupancy' => $this->occupancy->forLots($tenantId, [(string) $lot->id])[(string) $lot->id],
        ];
    }
}
