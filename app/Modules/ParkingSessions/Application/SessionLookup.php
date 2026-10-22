<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;

/**
 * Loads sessions only through the active tenant. A session of another
 * tenant is reported as missing, never as forbidden.
 */
final class SessionLookup
{
    public function find(string $tenantId, string $sessionId, bool $lock = false): ParkingSession
    {
        $query = ParkingSession::query()->where('tenant_id', $tenantId)->whereKey($sessionId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function byEntryKey(string $tenantId, string $key): ?ParkingSession
    {
        return ParkingSession::query()->where('tenant_id', $tenantId)->where('entry_idempotency_key', $key)->first();
    }

    public function byExitKey(string $tenantId, string $key): ?ParkingSession
    {
        return ParkingSession::query()->where('tenant_id', $tenantId)->where('exit_idempotency_key', $key)->first();
    }
}
