<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

final class PlateFormat
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?VehicleType $suggestedType,
    ) {}
}
