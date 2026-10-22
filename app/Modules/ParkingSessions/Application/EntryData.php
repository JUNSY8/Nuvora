<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Vehicles\Domain\VehicleType;

final class EntryData
{
    /**
     * @param  string|null  $spaceId  space chosen by the operator; null with $autoAssign false admits without a space
     */
    public function __construct(
        public readonly string $lotId,
        public readonly string $plate,
        public readonly string $country,
        public readonly ?VehicleType $vehicleType,
        public readonly ?string $spaceId,
        public readonly bool $autoAssign,
        public readonly ?string $gateId,
        public readonly ?string $notes,
        public readonly ?string $idempotencyKey,
    ) {}
}
