<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleRelationship;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Infrastructure\Persistence\CustomerVehicle;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegisterVehicle
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly CustomerDirectory $customers,
        private readonly VehicleTimeline $timeline,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * Optionally links the first customer in the same transaction, so a
     * vehicle registered from a customer profile never ends up orphaned.
     */
    public function handle(
        string $actorUserId,
        string $tenantId,
        VehicleData $data,
        ?string $customerId = null,
        VehicleRelationship $relationship = VehicleRelationship::Owner,
    ): Vehicle {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_CREATE);

        $attributes = VehicleAttributes::resolve($tenantId, $data);
        $customer = $customerId === null ? null : $this->customers->summary($tenantId, $customerId);

        if ($customerId !== null && ($customer === null || $customer['status'] !== 'active')) {
            throw ValidationException::withMessages(['customer_id' => 'Selecciona un cliente activo de esta empresa.']);
        }

        try {
            $vehicle = DB::transaction(function () use ($tenantId, $actorUserId, $attributes, $customer, $relationship): Vehicle {
                $vehicle = Vehicle::query()->create([
                    'tenant_id' => $tenantId,
                    'status' => VehicleStatus::Active,
                    ...$attributes,
                ]);

                $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::Registered, $actorUserId, [
                    'plate' => $vehicle->plate,
                    'country' => $vehicle->country,
                    'type' => $vehicle->type->value,
                ]);

                if ($customer !== null) {
                    CustomerVehicle::query()->create([
                        'tenant_id' => $tenantId,
                        'customer_id' => $customer['id'],
                        'vehicle_id' => $vehicle->id,
                        'relationship' => $relationship,
                        'started_at' => now(),
                    ]);

                    $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::CustomerLinked, $actorUserId, [
                        'customer_id' => $customer['id'],
                        'customer_name' => $customer['name'],
                        'relationship' => $relationship->value,
                    ]);
                }

                return $vehicle;
            });
        } catch (UniqueConstraintViolationException) {
            throw VehicleAttributes::plateTaken();
        }

        $this->audit->record(
            action: 'vehicle.created',
            resourceType: 'vehicle',
            resourceId: (string) $vehicle->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'plate' => $vehicle->plate,
                'country' => $vehicle->country,
                'type' => $vehicle->type->value,
                'customer_id' => $customer['id'] ?? null,
            ],
        );

        return $vehicle;
    }
}
