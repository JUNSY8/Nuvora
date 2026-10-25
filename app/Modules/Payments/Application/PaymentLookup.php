<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Infrastructure\Persistence\Payment;

/**
 * Loads payments only through the active tenant. A payment of another
 * tenant is reported as missing, never as forbidden.
 */
final class PaymentLookup
{
    public function find(string $tenantId, string $paymentId, bool $lock = false): Payment
    {
        $query = Payment::query()->where('tenant_id', $tenantId)->whereKey($paymentId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function byKey(string $tenantId, string $key): ?Payment
    {
        return Payment::query()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
    }
}
