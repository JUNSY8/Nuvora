<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Memberships\Application\MembershipDirectory;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Parking\Application\ParkingSpaceAllocator;
use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\ParkingLotStatus;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Domain\SpaceCompatibility;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\ParkingSessions\Domain\SessionRuleViolation;
use App\Modules\ParkingSessions\Domain\SessionSource;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Reservations\Application\ReservationDirectory;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Application\VehicleDirectory;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual entry: identify the vehicle, check that it may enter, check in its
 * reservation if it has one, hold a space and open the session in one
 * transaction. The partial unique indexes on
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
        private readonly ReservationDirectory $reservations,
        private readonly MembershipDirectory $memberships,
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

        $notices = [];

        try {
            $session = DB::transaction(function () use ($actorUserId, $tenantId, $lot, $data, &$notices): ParkingSession {
                $notices = [];

                return $this->open($actorUserId, $tenantId, $lot, $data, $notices);
            }, 3);
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
                'reservation_id' => $session->reservation_id,
                'membership_id' => $session->membership_id,
            ],
        );

        $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, $lot['id']));

        return new SessionOutcome($session, false, $notices);
    }

    /**
     * @param  array{id: string, timezone: string}  $lot
     * @param  list<array{type: string, message: string}>  $notices  what the operator should know about this entry
     */
    private function open(string $actorUserId, string $tenantId, array $lot, EntryData $data, array &$notices): ParkingSession
    {
        $lotId = $lot['id'];
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

        $now = now()->toImmutable();
        $reservation = $this->reservations->arrival($tenantId, $lotId, $vehicle['id'], $now);
        $access = $this->memberships->accessFor($tenantId, $lotId, $lot['timezone'], $vehicle['id'], $vehicle['type'], $now);
        $membership = $access !== null && $access['decision']->covered ? $access['membership'] : null;
        $space = $this->chooseSpace($tenantId, $lotId, $data, $vehicle, $reservation, $membership);

        if ($access !== null && ! $access['decision']->covered) {
            $notices[] = ['type' => 'warning', 'message' => "Mensualidad {$access['membership']['code']}: {$access['decision']->reason} La estancia se cobra como visitante."];
        }

        // A plan limited to some zones covers only stays parked in them.
        if ($membership !== null && $membership['zone_ids'] !== []) {
            $zoneId = $space === null ? null : $this->parking->spaceZoneId($tenantId, $lotId, $space['id']);

            if (! in_array($zoneId, $membership['zone_ids'], true)) {
                $notices[] = ['type' => 'warning', 'message' => "La mensualidad {$membership['code']} no cubre este espacio o zona. La estancia se cobra como visitante."];
                $membership = null;
            }
        }

        if ($membership !== null) {
            $notices[] = ['type' => 'info', 'message' => "Mensualidad {$membership['code']} ({$membership['plan']}): la estancia queda cubierta."];
        }

        $session = ParkingSession::query()->create([
            'tenant_id' => $tenantId,
            'parking_lot_id' => $lotId,
            'vehicle_id' => $vehicle['id'],
            // A covered stay belongs to whoever holds the membership that pays it.
            'customer_id' => $membership['customer_id'] ?? $this->vehicles->primaryCustomerId($tenantId, $vehicle['id']),
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
            'reservation_id' => $reservation['id'] ?? null,
            'membership_id' => $membership['id'] ?? null,
        ]);

        if ($reservation !== null) {
            $this->reservations->checkIn($tenantId, $reservation['id'], $actorUserId, $now);
        }

        $this->vehicles->recordStayEvent($tenantId, $vehicle['id'], VehicleEventType::Entered, $actorUserId, [
            'session_id' => (string) $session->id,
            'parking_lot_id' => $lotId,
            'space_code' => $session->space_code,
            'reservation_code' => $reservation['code'] ?? null,
            'membership_code' => $membership['code'] ?? null,
        ]);

        return $session;
    }

    /**
     * An operator's choice wins. A reservation for a space takes that space
     * (held for it) or, if a walk-in occupies it, another in its zone; a
     * reservation for a zone takes a space of that zone. Arrivals with a
     * reservation use reservable spaces first; walk-ins use them last. A
     * member takes the space assigned to the membership when it is free, or
     * one in the plan's zones.
     *
     * @param  array{type: VehicleType, is_electric: bool}  $vehicle
     * @param  array{id: string, code: string, zone_id: string|null, space_id: string|null, space_code: string|null}|null  $reservation
     * @param  array{assigned_lot_id: string|null, assigned_space_id: string|null, zone_ids: list<string>}|null  $membership
     * @return array{id: string, code: string, type: SpaceType}|null
     */
    private function chooseSpace(string $tenantId, string $lotId, EntryData $data, array $vehicle, ?array $reservation, ?array $membership): ?array
    {
        if ($data->spaceId !== null) {
            $space = $this->spaces->occupy($tenantId, $lotId, $data->spaceId, allowHeld: $reservation !== null && $reservation['space_id'] === $data->spaceId);

            if (! SpaceCompatibility::accepts($space['type'], $vehicle['type'])) {
                throw new SessionRuleViolation("El espacio {$space['code']} no admite este tipo de vehículo.", 'SPACE_INCOMPATIBLE');
            }

            return $space;
        }

        if (! $data->autoAssign) {
            return null;
        }

        if ($reservation !== null && $reservation['space_id'] !== null) {
            try {
                return $this->spaces->occupy($tenantId, $lotId, $reservation['space_id'], allowHeld: true);
            } catch (ParkingRuleViolation) {
                // Taken by a walk-in before the hold started: place the arrival elsewhere.
            }
        }

        $preferred = SpaceCompatibility::preferred($vehicle['type'], $vehicle['is_electric']);

        if ($reservation === null && $membership !== null) {
            if ($membership['assigned_space_id'] !== null && $membership['assigned_lot_id'] === $lotId) {
                try {
                    return $this->spaces->occupy($tenantId, $lotId, $membership['assigned_space_id']);
                } catch (ParkingRuleViolation) {
                    // The assigned space is taken or out of service: place the member elsewhere.
                }
            }

            foreach ($membership['zone_ids'] as $zoneId) {
                $space = $this->spaces->occupyFirstAvailable($tenantId, $lotId, $preferred, $zoneId);

                if ($space !== null) {
                    return $space;
                }
            }
        }

        $space = $reservation === null
            ? $this->spaces->occupyFirstAvailable($tenantId, $lotId, $preferred)
            : ($this->spaces->occupyFirstAvailable($tenantId, $lotId, $preferred, $reservation['zone_id'], reservableFirst: true)
                ?? $this->spaces->occupyFirstAvailable($tenantId, $lotId, $preferred, reservableFirst: true));

        if ($space === null) {
            throw new SessionRuleViolation('No hay espacios disponibles para este tipo de vehículo. Elige uno a mano o registra la entrada sin espacio.', 'NO_SPACE_AVAILABLE');
        }

        return $space;
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
