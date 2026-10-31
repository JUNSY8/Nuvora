<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Infrastructure\Persistence\Customer;

final class CustomerLookup
{
    public function find(string $tenantId, string $customerId, bool $lock = false): Customer
    {
        $query = Customer::query()->where('tenant_id', $tenantId)->whereKey($customerId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
