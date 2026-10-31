<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Domain\CustomerStatus;
use App\Modules\Customers\Infrastructure\Persistence\Customer;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class ChangeCustomerStatus
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CustomerLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $customerId, CustomerStatus $target): Customer
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::CUSTOMERS_UPDATE);

        $customer = $this->lookup->find($tenantId, $customerId);
        $from = $customer->status;

        if ($from !== $target) {
            $customer->fill(['status' => $target])->save();

            $this->audit->record(
                action: 'customer.status_changed',
                resourceType: 'customer',
                resourceId: (string) $customer->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['from' => $from->value, 'to' => $target->value],
            );
        }

        return $customer->load('business:id,name')->loadCount('members');
    }
}
