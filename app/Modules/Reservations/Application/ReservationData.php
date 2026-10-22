<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;

/**
 * A reservation request. Times are already in UTC; a space narrows the
 * reservation to that space, a zone to that zone, neither to the lot.
 */
final class ReservationData
{
    public function __construct(
        public readonly string $lotId,
        public readonly string $plate,
        public readonly string $country,
        public readonly ?VehicleType $vehicleType,
        public readonly ?string $customerId,
        public readonly ?string $zoneId,
        public readonly ?string $spaceId,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly bool $confirm,
        public readonly ?string $notes,
        public readonly ?string $idempotencyKey,
    ) {}
}
