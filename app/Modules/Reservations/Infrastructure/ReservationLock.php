<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Infrastructure;

use Illuminate\Support\Facades\DB;

/**
 * Serialises the reservations of one lot inside the caller's transaction.
 * Capacity is a property of the whole lot, so two concurrent bookings must
 * not both read the same free capacity. A PostgreSQL advisory lock does it
 * without locking the lot row, which entries reference. SQLite already
 * serialises writers.
 */
final class ReservationLock
{
    public function forLot(string $lotId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['reservations:'.$lotId]);
    }
}
