<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

/**
 * Country-specific plate knowledge. Implementations live outside the
 * universal domain so local formats do not leak into it.
 */
interface PlateRules
{
    public function country(): string;

    public function recognize(Plate $plate): ?PlateFormat;
}
