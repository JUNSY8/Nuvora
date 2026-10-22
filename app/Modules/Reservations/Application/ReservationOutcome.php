<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Reservations\Infrastructure\Persistence\Reservation;

/**
 * Result of booking: a replay returns the reservation the first request
 * created instead of booking again.
 */
final class ReservationOutcome
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly bool $replayed,
    ) {}
}
