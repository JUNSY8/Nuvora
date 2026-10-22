<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Tariffs\Infrastructure\Persistence\Discount;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;

/**
 * Loads tariffs and discounts only through the active tenant. A record of
 * another tenant is reported as missing, never as forbidden.
 */
final class TariffLookup
{
    public function tariff(string $tenantId, string $tariffId, bool $lock = false): Tariff
    {
        $query = Tariff::query()->where('tenant_id', $tenantId)->whereKey($tariffId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function discount(string $tenantId, string $discountId, bool $lock = false): Discount
    {
        $query = Discount::query()->where('tenant_id', $tenantId)->whereKey($discountId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
