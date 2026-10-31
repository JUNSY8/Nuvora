<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\DiscountType;
use App\Modules\Vehicles\Domain\VehicleType;

final class DiscountData
{
    /**
     * @param  list<VehicleType>  $vehicleTypes
     * @param  list<int>  $days
     */
    public function __construct(
        public readonly ?string $lotId,
        public readonly string $code,
        public readonly string $name,
        public readonly DiscountType $type,
        public readonly int $value,
        public readonly ?string $currency,
        public readonly DiscountApplication $application,
        public readonly array $vehicleTypes,
        public readonly array $days,
        public readonly ?string $validFrom,
        public readonly ?string $validUntil,
    ) {}
}
