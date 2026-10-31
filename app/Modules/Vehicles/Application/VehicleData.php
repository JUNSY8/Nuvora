<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Vehicles\Domain\VehicleType;

final class VehicleData
{
    public function __construct(
        public readonly string $plate,
        public readonly string $country,
        public readonly VehicleType $type,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $color,
        public readonly ?int $year,
        public readonly bool $isElectric,
        public readonly ?string $notes,
    ) {}
}
