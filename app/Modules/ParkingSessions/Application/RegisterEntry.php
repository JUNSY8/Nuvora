<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Parking\Application\ParkingSpaceAllocator;
use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\ParkingLotStatus;
use App\Modules\ParkingSessions\Domain\SessionRuleViolation;
use App\Modules\ParkingSessions\Domain\SessionSource;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Domain\SpaceCompatibility;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Application\VehicleDirectory;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual entry: identify the vehicle, check that it may enter, hold a space
 * and open the session in one transaction. The partial unique indexes on
 * open sessions decide races between operators; an Idempotency-Key makes a
 * retried request return the session it already created.
 */
final class RegisterEntry
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly ParkingSpaceAllocator $spaces,
        private readonly VehicleDirectory $vehicles,
        private readonly GateCheck $gates,
        private readonly SessionLookup $lookup,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function handle(string $actorUserId, string $tenantId, EntryData $data): SessionOutcome
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_CREATE);

        $lot = $this->parking->lot($tenantId, $data->lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'Selecciona un parqueadero de esta empresa.']);

        if ($data->idempotencyKey !== null && ($previous = $this->replay($tenantId, $data)) !== null) {
            return new SessionOutcome($previous, true);
        }

        if ($lot['status'] !== ParkingLotStatus::Active) {
            throw new SessionRuleViolation("El parqueadero {$lot['name']} no está operando. Actívalo para registrar entradas.", 'LOT_NOT_OPERATING');
        }

        $this->gates->assertUsable($tenantId, $lot['id'], $data->gateId, GateDirection::Entry);

        try {
            $session = DB::transaction(fn (): ParkingSession => $this->open($actorUserId, $tenantId, $lot['id'], $data), 3);
        } catch (UniqueConstraintViolationException) {
            if ($data->idempotencyKey !== null && ($previous = $this->replay($tenantId, $data)) !== null) {
                return new SessionOutcome($previous, true);
            }

            // Another operator admitted the same vehicle or took the space first.
            throw new SessionRuleViolation('Otra operación registró este vehículo o tomó el espacio al mismo tiempo. Actualiza e intenta de nuevo.', 'ENTRY_CONFLICT', 409);
        }

        $this->audit->record(
            action: 'parking_session.opened',
            resourceType: 'parking_session',
            resourceId: (string) $session->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'parking_lot_id' => $lot['id'],
                'plate' => $session->plate,
                'space_code' => $session->space_code,
                'entry_gate_id' => $session->entry_gate_id,
            ],
        );

        $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, $lot['id']));

        return new SessionOutcome($session, false);
    }

    private function open(string $actorUserId, string $tenantId, string $lotId, EntryData $data): ParkingSession
    {
        $vehicle = $this->vehicles->identifyOrRegister($tenantId, $actorUserId, $data->plate, $data->country, $data->vehicleType);

        if ($vehicle['status'] === VehicleStatus::Blocked) {
            $reason = $vehicle['status_reason'] === null ? '' : ": {$vehicle['status_reason']}";

            throw new SessionRuleViolation("El vehículo {$vehicle['plate']} está bloqueado{$reason}.", 'VEHICLE_BLOCKED');
        }

        $inside = ParkingSession::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->where('vehicle_id', $vehicle['id'])
            ->where('status', SessionStatus::Open->value)
            ->first(['id', 'space_code', 'entry_at']);

        if ($inside !== null) {
            $where = $inside->space_code === null ? '' : " en el espacio {$inside->space_code}";

            throw new SessionRuleViolation("El vehículo {$vehicle['plate']} ya está dentro{$where}. Registra su salida primero.", 'VEHICLE_ALREADY_INSIDE');
        }

        $space = null;

        if ($data->spaceId !== null) {
            $space = $this->spaces->occupy($tenantId, $lotId, $data->spaceId);

            if (! SpaceCompatibility::accepts($space['type'], $vehicle['type'])) {
                throw new SessionRuleViolation("El espacio {$space['code']} no admite este tipo de vehículo.", 'SPACE_INCOMPATIBLE');
            }
        } elseif ($data->autoAssign) {
            $space = $this->spaces->occupyFirstAvailable($tenantId, $lotId, SpaceCompatibility::preferred($vehicle['type'], $vehicle['is_electric']));

            if ($space === null) {
                throw new SessionRuleViolation('No hay espacios disponibles para este tipo de vehículo. Elige uno a mano o registra la entrada sin espacio.', 'NO_SPACE_AVAILABLE');
            }
        }

        $session = ParkingSession::query()->create([
            'tenant_id' => $tenantId,
            'parking_lot_id' => $lotId,
            'vehicle_id' => $vehicle['id'],
            'customer_id' => $this->vehicles->primaryCustomerId($tenantId, $vehicle['id']),
            'space_id' => $space['id'] ?? null,
            'plate' => $vehicle['plate'],
            'country' => $vehicle['country'],
            'vehicle_type' => $vehicle['type']->value,
            'space_code' => $space['code'] ?? null,
            'status' => SessionStatus::Open,
            'entry_at' => now(),
            'entry_source' => SessionSource::Manual,
            'entry_gate_id' => $data->gateId,
            'opened_by' => $actorUserId,
            'entry_idempotency_key' => $data->idempotencyKey,
            'notes' => $data->notes,
        ]);

        $this->vehicles->recordStayEvent($tenantId, $vehicle['id'], VehicleEventType::Entered, $actorUserId, [
            'session_id' => (string) $session->id,
            'parking_lot_id' => $lotId,
            'space_code' => $session->space_code,
        ]);

        return $session;
    }

    /**
     * A key already used for an entry returns that session when it describes
     * the same entry, and is rejected when it is reused for another one.
     */
    private function replay(string $tenantId, EntryData $data): ?ParkingSession
    {
        $previous = $this->lookup->byEntryKey($tenantId, (string) $data->idempotencyKey);

        if ($previous === null) {
            return null;
        }

        $samePlate = Plate::searchTerm($data->plate) === $previous->plate;

        if ((string) $previous->parking_lot_id !== $data->lotId || ! $samePlate) {
            throw new SessionRuleViolation('La clave de idempotencia ya se usó para otra entrada.', 'IDEMPOTENCY_KEY_REUSED', 409);
        }

        return $previous;
    }
}
