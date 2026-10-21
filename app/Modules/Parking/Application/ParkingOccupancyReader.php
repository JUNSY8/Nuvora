<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\Occupancy;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;

final class ParkingOccupancyReader
{
    /**
     * One grouped query for every requested lot.
     *
     * @param  list<string>  $lotIds
     * @return array<string, Occupancy>
     */
    public function forLots(string $tenantId, array $lotIds): array
    {
        $counts = [];

        if ($lotIds !== []) {
            $rows = ParkingSpace::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('parking_lot_id', $lotIds)
                ->selectRaw('parking_lot_id, status, count(*) as aggregate')
                ->groupBy('parking_lot_id', 'status')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $counts[(string) $row->parking_lot_id][(string) $row->status] = (int) $row->aggregate;
            }
        }

        $occupancy = [];

        foreach ($lotIds as $lotId) {
            $occupancy[$lotId] = Occupancy::fromCounts($counts[$lotId] ?? []);
        }

        return $occupancy;
    }
}
