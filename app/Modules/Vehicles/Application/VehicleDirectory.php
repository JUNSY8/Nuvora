<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Vehicles\Domain\CountryCode;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleRelationship;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use App\Modules\Vehicles\Infrastructure\Persistence\CustomerVehicle;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public contract of the Vehicles module for parking sessions and
 * reservations (and later access control and LPR). Every answer is scoped
 * to a tenant. The caller's own permission authorizes it; this contract
 * does not check vehicle permissions, so an operator can admit or book a
 * visitor without vehicles.create.
 */
final class VehicleDirectory
{
    public function __construct(private readonly VehicleTimeline $timeline) {}

    /**
     * Finds the vehicle by canonical plate and country or registers a minimal
     * visitor record. The type is only used for a new vehicle; an existing
     * vehicle keeps its own. Must run inside the caller's transaction.
     *
     * @return array{id: string, plate: string, country: string, type: VehicleType, is_electric: bool, status: VehicleStatus, status_reason: string|null, registered: bool}
     */
    public function identifyOrRegister(string $tenantId, string $actorUserId, string $plateInput, string $country, ?VehicleType $type, string $source = 'entry'): array
    {
        $plate = Plate::fromInput($plateInput);
        $country = CountryCode::normalize($country);

        $vehicle = $this->findByPlate($tenantId, $plate->value, $country);

        if ($vehicle !== null) {
            return $this->identity($vehicle, false);
        }

        if ($type === null) {
            throw ValidationException::withMessages(['vehicle_type' => 'Indica el tipo de vehículo: la placa no está registrada.']);
        }

        try {
            // Savepoint: a concurrent registration of the same plate must not abort the caller's transaction.
            $vehicle = DB::transaction(fn (): Vehicle => Vehicle::query()->create([
                'tenant_id' => $tenantId,
                'plate' => $plate->value,
                'country' => $country,
                'type' => $type,
                'is_electric' => false,
                'status' => VehicleStatus::Active,
            ]));
        } catch (UniqueConstraintViolationException) {
            $vehicle = $this->findByPlate($tenantId, $plate->value, $country);

            if ($vehicle === null) {
                throw ValidationException::withMessages(['plate' => 'No fue posible registrar la placa. Intenta de nuevo.']);
            }

            return $this->identity($vehicle, false);
        }

        $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::Registered, $actorUserId, [
            'plate' => $vehicle->plate,
            'country' => $vehicle->country,
            'type' => $vehicle->type->value,
            'source' => $source,
        ]);

        return $this->identity($vehicle, true);
    }

    /**
     * Customer the vehicle's stays are attributed to: the active owner, or
     * else the oldest active link.
     */
    public function primaryCustomerId(string $tenantId, string $vehicleId): ?string
    {
        $links = CustomerVehicle::query()
            ->where('tenant_id', $tenantId)
            ->where('vehicle_id', $vehicleId)
            ->whereNull('ended_at')
            ->orderBy('started_at')
            ->get(['customer_id', 'relationship']);

        $owner = $links->first(fn (CustomerVehicle $link): bool => $link->relationship === VehicleRelationship::Owner);
        $link = $owner ?? $links->first();

        return $link === null ? null : (string) $link->customer_id;
    }

    /**
     * Appends a parking stay to the vehicle timeline.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordStayEvent(string $tenantId, string $vehicleId, VehicleEventType $type, string $actorUserId, array $data): void
    {
        $this->timeline->record($tenantId, $vehicleId, $type, $actorUserId, $data);
    }

    private function findByPlate(string $tenantId, string $plate, string $country): ?Vehicle
    {
        return Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->where('country', $country)
            ->where('plate', $plate)
            ->first();
    }

    /**
     * @return array{id: string, plate: string, country: string, type: VehicleType, is_electric: bool, status: VehicleStatus, status_reason: string|null, registered: bool}
     */
    private function identity(Vehicle $vehicle, bool $registered): array
    {
        return [
            'id' => (string) $vehicle->id,
            'plate' => $vehicle->plate,
            'country' => $vehicle->country,
            'type' => $vehicle->type,
            'is_electric' => $vehicle->is_electric,
            'status' => $vehicle->status,
            'status_reason' => $vehicle->status_reason,
            'registered' => $registered,
        ];
    }
}
