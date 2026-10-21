<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Infrastructure\Persistence\Customer;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class UpdateCustomer
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CustomerLookup $lookup,
        private readonly CustomerAttributes $attributes,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $customerId, CustomerData $data): Customer
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::CUSTOMERS_UPDATE);

        $customer = $this->lookup->find($tenantId, $customerId);
        $attributes = $this->attributes->resolve($tenantId, $data, $customer);

        $customer->fill($attributes);
        $changed = array_keys($customer->getDirty());
        CustomerAttributes::write(fn (): bool => $customer->save());

        if ($changed !== []) {
            $this->audit->record(
                action: 'customer.updated',
                resourceType: 'customer',
                resourceId: (string) $customer->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['changed' => array_values(array_diff($changed, ['document_normalized']))],
            );
        }

        return $customer->load('business:id,name')->loadCount('members');
    }
}
