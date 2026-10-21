<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\ParkingLotStatus;

final class ParkingLotData
{
    public function __construct(
        public readonly string $name,
        public readonly string $code,
        public readonly ?string $description,
        public readonly ?string $address,
        public readonly ?string $city,
        public readonly ?string $latitude,
        public readonly ?string $longitude,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly int $gracePeriodMinutes,
        public readonly ParkingLotStatus $status,
    ) {}
}
