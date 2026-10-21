<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Infrastructure\Persistence\CustomerVehicle;
use Illuminate\Support\Facades\DB;

final class EndVehicleLink
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CustomerDirectory $customers,
        private readonly VehicleTimeline $timeline,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $vehicleId, string $linkId): void
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_UPDATE);

        $link = DB::transaction(function () use ($tenantId, $vehicleId, $linkId, $actorUserId): CustomerVehicle {
            $link = CustomerVehicle::query()
                ->where('tenant_id', $tenantId)
                ->where('vehicle_id', $vehicleId)
                ->whereNull('ended_at')
                ->whereKey($linkId)
                ->lockForUpdate()
                ->firstOrFail();

            $link->fill(['ended_at' => now()])->save();
            $customer = $this->customers->summary($tenantId, (string) $link->customer_id);

            $this->timeline->record($tenantId, $vehicleId, VehicleEventType::CustomerUnlinked, $actorUserId, [
                'customer_id' => (string) $link->customer_id,
                'customer_name' => $customer['name'] ?? null,
                'relationship' => $link->relationship->value,
                'reason' => 'manual',
            ]);

            return $link;
        });

        $this->audit->record(
            action: 'vehicle.customer_unlinked',
            resourceType: 'vehicle',
            resourceId: $vehicleId,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['customer_id' => (string) $link->customer_id, 'relationship' => $link->relationship->value],
        );
    }
}
