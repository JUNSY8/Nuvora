<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Infrastructure;

use Illuminate\Support\Facades\DB;

/**
 * Serialises, inside the caller's transaction, the writes that count rows of
 * other memberships: a plan's capacity and a vehicle that may only belong to
 * one membership at a time. PostgreSQL advisory locks; SQLite already
 * serialises writers.
 */
final class MembershipLock
{
    public function forPlan(string $planId): void
    {
        $this->lock('memberships:plan:'.$planId);
    }

    public function forVehicle(string $vehicleId): void
    {
        $this->lock('memberships:vehicle:'.$vehicleId);
    }

    private function lock(string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }
}
