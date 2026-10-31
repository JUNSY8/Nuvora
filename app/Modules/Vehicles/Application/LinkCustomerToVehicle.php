<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleRelationship;
use App\Modules\Vehicles\Domain\VehicleRuleViolation;
use App\Modules\Vehicles\Infrastructure\Persistence\CustomerVehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Links a customer to a vehicle, changes an existing link's relationship or
 * transfers ownership. The vehicle row is locked so two operators cannot
 * assign competing owners; a partial unique index backs the rule.
 */
final class LinkCustomerToVehicle
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly VehicleLookup $lookup,
        private readonly CustomerDirectory $customers,
        private readonly VehicleTimeline $timeline,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        string $actorUserId,
        string $tenantId,
        string $vehicleId,
        string $customerId,
        VehicleRelationship $relationship,
        bool $transferOwnership = false,
    ): CustomerVehicle {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_UPDATE);

        $customer = $this->customers->summary($tenantId, $customerId);

        if ($customer === null || $customer['status'] !== 'active') {
            throw ValidationException::withMessages(['customer_id' => 'Selecciona un cliente activo de esta empresa.']);
        }

        try {
            /** @var array{0: CustomerVehicle, 1: VehicleRelationship|null, 2: string|null} $result */
            $result = DB::transaction(function () use ($tenantId, $vehicleId, $customer, $relationship, $transferOwnership, $actorUserId): array {
                $vehicle = $this->lookup->find($tenantId, $vehicleId, lock: true);
                $active = CustomerVehicle::query()
                    ->where('tenant_id', $tenantId)
                    ->where('vehicle_id', $vehicle->id)
                    ->whereNull('ended_at')
                    ->get();

                $existing = $active->first(fn (CustomerVehicle $link): bool => $link->customer_id === $customer['id']);

                if ($existing !== null && $existing->relationship === $relationship) {
                    throw new VehicleRuleViolation('El cliente ya está vinculado al vehículo con esa relación.', 'ALREADY_LINKED');
                }

                $replacedOwner = null;

                if ($relationship === VehicleRelationship::Owner) {
                    $owner = $active->first(fn (CustomerVehicle $link): bool => $link->relationship === VehicleRelationship::Owner && $link->customer_id !== $customer['id']);

                    if ($owner !== null && ! $transferOwnership) {
                        throw self::hasOwner();
                    }

                    if ($owner !== null) {
                        $owner->fill(['ended_at' => now()])->save();
                        $replacedOwner = (string) $owner->customer_id;
                        $previous = $this->customers->summary($tenantId, $replacedOwner);

                        $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::CustomerUnlinked, $actorUserId, [
                            'customer_id' => $replacedOwner,
                            'customer_name' => $previous['name'] ?? null,
                            'relationship' => VehicleRelationship::Owner->value,
                            'reason' => 'ownership_transferred',
                        ]);
                    }
                }

                if ($existing !== null) {
                    $from = $existing->relationship;
                    $existing->fill(['relationship' => $relationship])->save();

                    $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::RelationshipChanged, $actorUserId, [
                        'customer_id' => $customer['id'],
                        'customer_name' => $customer['name'],
                        'from' => $from->value,
                        'to' => $relationship->value,
                    ]);

                    return [$existing, $from, $replacedOwner];
                }

                $link = CustomerVehicle::query()->create([
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

                return [$link, null, $replacedOwner];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won the owner slot or the same link.
            throw self::hasOwner();
        }

        [$link, $from, $replacedOwner] = $result;

        $this->audit->record(
            action: $from === null ? 'vehicle.customer_linked' : 'vehicle.relationship_changed',
            resourceType: 'vehicle',
            resourceId: $vehicleId,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: array_filter([
                'customer_id' => $customer['id'],
                'relationship' => $relationship->value,
                'from' => $from?->value,
                'replaced_owner_id' => $replacedOwner,
            ], fn ($value): bool => $value !== null),
        );

        return $link;
    }

    private static function hasOwner(): VehicleRuleViolation
    {
        return new VehicleRuleViolation(
            'El vehículo ya tiene un propietario activo. Transfiere la propiedad o vincula al cliente como conductor.',
            'VEHICLE_HAS_OWNER',
        );
    }
}
