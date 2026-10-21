<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\CustomerStatus;
use App\Modules\Customers\Infrastructure\Persistence\Customer;

/**
 * Public contract of the Customers module for other modules (Vehicles
 * today, Sessions, Reservations and Memberships later). Every answer is
 * scoped to a tenant.
 */
final class CustomerDirectory
{
    /**
     * @return array{id: string, name: string, type: string, status: string}|null
     */
    public function summary(string $tenantId, string $customerId): ?array
    {
        return $this->summaries($tenantId, [$customerId])[$customerId] ?? null;
    }

    public function isActive(string $tenantId, string $customerId): bool
    {
        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($customerId)
            ->where('status', CustomerStatus::Active->value)
            ->exists();
    }

    /**
     * @param  list<string>  $customerIds
     * @return array<string, array{id: string, name: string, type: string, status: string}>
     */
    public function summaries(string $tenantId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $customerIds)
            ->get(['id', 'name', 'type', 'status'])
            ->mapWithKeys(fn (Customer $customer): array => [
                (string) $customer->id => [
                    'id' => (string) $customer->id,
                    'name' => $customer->name,
                    'type' => $customer->type->value,
                    'status' => $customer->status->value,
                ],
            ])
            ->all();
    }
}
