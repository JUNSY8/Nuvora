<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Infrastructure\Persistence\Customer;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

final class ShowCustomer
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CustomerLookup $lookup,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $customerId): Customer
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::CUSTOMERS_VIEW);

        return $this->lookup->find($tenantId, $customerId)->load('business:id,name')->loadCount('members');
    }
}
