<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Memberships\Infrastructure\Persistence\Membership;

/**
 * Loads memberships only through the active tenant. A membership of
 * another tenant is reported as missing, never as forbidden.
 */
final class MembershipLookup
{
    public function find(string $tenantId, string $membershipId, bool $lock = false): Membership
    {
        $query = Membership::query()->where('tenant_id', $tenantId)->whereKey($membershipId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
