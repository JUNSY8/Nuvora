<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Reservations\Infrastructure\Persistence\Reservation;

/**
 * Loads reservations only through the active tenant. A reservation of
 * another tenant is reported as missing, never as forbidden.
 */
final class ReservationLookup
{
    public function find(string $tenantId, string $reservationId, bool $lock = false): Reservation
    {
        $query = Reservation::query()->where('tenant_id', $tenantId)->whereKey($reservationId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function byKey(string $tenantId, string $key): ?Reservation
    {
        return Reservation::query()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
    }
}
