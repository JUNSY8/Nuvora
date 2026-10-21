<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Domain\CustomerStatus;
use App\Modules\Customers\Infrastructure\Persistence\Customer;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class CreateCustomer
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CustomerAttributes $attributes,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, CustomerData $data): Customer
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::CUSTOMERS_CREATE);

        $attributes = $this->attributes->resolve($tenantId, $data);

        $customer = CustomerAttributes::write(fn (): Customer => Customer::query()->create([
            'tenant_id' => $tenantId,
            'status' => CustomerStatus::Active,
            ...$attributes,
        ]));

        // Contact and document values are personal data: the audit trail
        // records which fields were provided, not their content.
        $this->audit->record(
            action: 'customer.created',
            resourceType: 'customer',
            resourceId: (string) $customer->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'type' => $customer->type->value,
                'business_id' => $customer->business_id,
                'provided' => array_keys(array_filter([
                    'document' => $customer->document_number !== null,
                    'email' => $customer->email !== null,
                    'phone' => $customer->phone !== null,
                ])),
            ],
        );

        return $customer->load('business:id,name')->loadCount('members');
    }
}
