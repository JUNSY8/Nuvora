<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;

/**
 * Resolves, in one query, the customers behind the active links of a page
 * of vehicles. Only name, type and status cross the module boundary.
 */
final class VehicleLinkSummaries
{
    public function __construct(private readonly CustomerDirectory $customers) {}

    /**
     * @param  iterable<Vehicle>  $vehicles
     * @return array<string, array{id: string, name: string, type: string, status: string}>
     */
    public function for(string $tenantId, iterable $vehicles): array
    {
        $ids = [];

        foreach ($vehicles as $vehicle) {
            foreach ($vehicle->activeLinks as $link) {
                $ids[(string) $link->customer_id] = true;
            }
        }

        return $this->customers->summaries($tenantId, array_keys($ids));
    }
}
