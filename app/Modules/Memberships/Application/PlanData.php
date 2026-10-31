<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Memberships\Domain\BillingPeriod;
use App\Modules\Memberships\Domain\PlanCategory;
use App\Modules\Vehicles\Domain\VehicleType;

final class PlanData
{
    /**
     * @param  list<string>  $lotIds
     * @param  list<string>  $zoneIds
     * @param  list<VehicleType>  $vehicleTypes
     * @param  array<mixed>  $windows
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly PlanCategory $category,
        public readonly BillingPeriod $billingPeriod,
        public readonly int $priceAmount,
        public readonly string $currency,
        public readonly int $graceDays,
        public readonly int $maxVehicles,
        public readonly ?int $capacity,
        public readonly array $lotIds,
        public readonly array $zoneIds,
        public readonly array $vehicleTypes,
        public readonly array $windows,
        public readonly ?string $description,
    ) {}
}
