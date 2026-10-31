<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use BackedEnum;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class UpdateVehicle
{
    private const DESCRIPTIVE_FIELDS = ['type', 'brand', 'model', 'color', 'year', 'is_electric'];

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly VehicleLookup $lookup,
        private readonly VehicleTimeline $timeline,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $vehicleId, VehicleData $data): Vehicle
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_UPDATE);

        $vehicle = $this->lookup->find($tenantId, $vehicleId);
        $attributes = VehicleAttributes::resolve($tenantId, $data, (string) $vehicle->id);
        $before = $this->snapshot($vehicle);

        $vehicle->fill($attributes);
        $dirty = array_keys($vehicle->getDirty());

        if ($dirty === []) {
            return $vehicle;
        }

        $after = $this->snapshot($vehicle);

        try {
            DB::transaction(function () use ($vehicle, $tenantId, $actorUserId, $before, $after, $dirty): void {
                $vehicle->save();

                if ($before['plate'] !== $after['plate'] || $before['country'] !== $after['country']) {
                    $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::PlateChanged, $actorUserId, [
                        'from' => $before['plate'],
                        'to' => $after['plate'],
                        'from_country' => $before['country'],
                        'to_country' => $after['country'],
                    ]);
                }

                $changes = [];

                foreach (self::DESCRIPTIVE_FIELDS as $field) {
                    if ($before[$field] !== $after[$field]) {
                        $changes[$field] = ['from' => $before[$field], 'to' => $after[$field]];
                    }
                }

                if (in_array('notes', $dirty, true)) {
                    $changes['notes'] = null;
                }

                if ($changes !== []) {
                    $this->timeline->record($tenantId, (string) $vehicle->id, VehicleEventType::Updated, $actorUserId, ['changes' => $changes]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw VehicleAttributes::plateTaken();
        }

        $this->audit->record(
            action: 'vehicle.updated',
            resourceType: 'vehicle',
            resourceId: (string) $vehicle->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['before' => $before, 'after' => $after],
        );

        return $vehicle;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Vehicle $vehicle): array
    {
        $values = [];

        foreach (['plate', 'country', ...self::DESCRIPTIVE_FIELDS] as $field) {
            $value = $vehicle->getAttribute($field);
            $values[$field] = $value instanceof BackedEnum ? $value->value : $value;
        }

        $values['year'] = $values['year'] === null ? null : (int) $values['year'];
        $values['is_electric'] = (bool) $values['is_electric'];

        return $values;
    }
}
