<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\SpaceType;

final class ParkingSpaceData
{
    public function __construct(
        public readonly ?string $zoneId,
        public readonly SpaceType $type,
        public readonly ?string $floor,
        public readonly bool $isReservable,
        public readonly bool $isAssignable,
    ) {}
}
