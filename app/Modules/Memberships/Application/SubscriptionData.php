<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Vehicles\Domain\VehicleType;

final class SubscriptionData
{
    /**
     * @param  list<array{plate: string, country: string, type: VehicleType|null}>  $vehicles
     */
    public function __construct(
        public readonly string $planId,
        public readonly string $customerId,
        public readonly string $startsOn,
        public readonly array $vehicles,
        public readonly ?string $assignedLotId,
        public readonly ?string $assignedSpaceId,
        public readonly bool $autoRenew,
        public readonly ?string $notes,
    ) {}
}
