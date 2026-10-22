<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;

/**
 * A stay to price. The caller measures the stay in minutes with its own
 * rule (sessions count a started minute as a full one); the entry decides
 * the tariff and the exit decides the promotions. The zone comes from the
 * space when there is one.
 */
final class PricingRequest
{
    public function __construct(
        public readonly string $lotId,
        public readonly VehicleType $vehicleType,
        public readonly DateTimeImmutable $entryAt,
        public readonly DateTimeImmutable $exitAt,
        public readonly int $stayMinutes,
        public readonly ?string $spaceId = null,
        public readonly ?string $zoneId = null,
        public readonly ?string $discountId = null,
    ) {}
}
