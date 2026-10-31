<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Parking\Domain\SpaceCompatibility;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\Reservations\Domain\ReservationCode;
use App\Modules\Reservations\Domain\ReservationRuleViolation;
use App\Modules\Reservations\Domain\ReservationStatus;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Reservations\Infrastructure\ReservationLock;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tariffs\Application\PricingEngine;
use App\Modules\Tariffs\Application\PricingRequest;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Application\VehicleDirectory;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\VehicleEventType;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a lot, a zone or a space for a window. Bookings of one lot are
 * serialised by a lock, so two clerks never sell the same last place; the
 * exclusion constraints of PostgreSQL still decide if anything slips
 * through. A confirmed booking is final; an unconfirmed one is a hold that
 * expires. The price is quoted with the tariffs in force at the start.
 */
final class CreateReservation
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly VehicleDirectory $vehicles,
        private readonly CustomerDirectory $customers,
        private readonly ReservationPolicies $policies,
        private readonly ReservationCalendar $calendar,
        private readonly ReservationSweeper $sweeper,
        private readonly ReservationLookup $lookup,
        private readonly ReservationLock $lock,
        private readonly PricingEngine $pricing,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function handle(string $actorUserId, string $tenantId, ReservationData $data): ReservationOutcome
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::RESERVATIONS_MANAGE);

        $lot = $this->parking->lot($tenantId, $data->lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'Selecciona un parqueadero de esta empresa.']);

        if ($data->idempotencyKey !== null && ($previous = $this->replay($tenantId, $data)) !== null) {
            return new ReservationOutcome($previous, true);
        }

        $now = now()->toImmutable();
        $policy = $this->policies->for($tenantId, $lot['id']);
        $window = $policy->window($data->startsAt, $data->endsAt, $now);

        if ($policy->withinOperatingHours
            && ! $this->parking->opensThroughout($tenantId, $lot['id'], $lot['timezone'], $data->startsAt, $data->endsAt)) {
            throw new ReservationRuleViolation('El parqueadero no está abierto durante todo ese horario.', 'OUTSIDE_OPERATING_HOURS');
        }

        if ($data->customerId !== null && $this->customers->summary($tenantId, $data->customerId) === null) {
            throw ValidationException::withMessages(['customer_id' => 'Selecciona un cliente de esta empresa.']);
        }

        $space = $this->space($tenantId, $lot['id'], $data);
        $zoneId = $space['zone_id'] ?? $data->zoneId;

        if ($space === null && $zoneId !== null && $this->parking->zone($tenantId, $lot['id'], $zoneId) === null) {
            throw ValidationException::withMessages(['zone_id' => 'La zona no pertenece a este parqueadero.']);
        }

        try {
            $reservation = DB::transaction(function () use ($actorUserId, $tenantId, $lot, $data, $policy, $window, $space, $zoneId, $now): Reservation {
                $this->lock->forLot($lot['id']);
                // Expired holds and no-shows must not take capacity from this booking.
                $this->sweeper->settleLot($tenantId, $lot['id'], $now);

                $vehicle = $this->vehicles->identifyOrRegister($tenantId, $actorUserId, $data->plate, $data->country, $data->vehicleType, 'reservation');

                if ($vehicle['status'] === VehicleStatus::Blocked) {
                    throw new ReservationRuleViolation("El vehículo {$vehicle['plate']} está bloqueado.", 'VEHICLE_BLOCKED');
                }

                if ($space !== null && ! SpaceCompatibility::accepts($space['type'], $vehicle['type'])) {
                    throw new ReservationRuleViolation("El espacio {$space['code']} no admite este tipo de vehículo.", 'SPACE_INCOMPATIBLE');
                }

                $this->calendar->assertFits(
                    $tenantId, $lot['id'], $vehicle['id'], $vehicle['type'], $zoneId, $space,
                    $data->startsAt, $data->endsAt, $window['blocked_from'], $window['blocked_until'],
                );

                $quote = $this->quote($tenantId, $lot['id'], $vehicle['type'], $zoneId, $data->startsAt, $data->endsAt);

                $reservation = Reservation::query()->create([
                    'tenant_id' => $tenantId,
                    'parking_lot_id' => $lot['id'],
                    'code' => $this->freeCode($tenantId),
                    'status' => $data->confirm ? ReservationStatus::Confirmed : ReservationStatus::Pending,
                    'vehicle_id' => $vehicle['id'],
                    'customer_id' => $data->customerId ?? $this->vehicles->primaryCustomerId($tenantId, $vehicle['id']),
                    'plate' => $vehicle['plate'],
                    'country' => $vehicle['country'],
                    'vehicle_type' => $vehicle['type']->value,
                    'zone_id' => $zoneId,
                    'space_id' => $space['id'] ?? null,
                    'space_code' => $space['code'] ?? null,
                    'starts_at' => $data->startsAt,
                    'ends_at' => $data->endsAt,
                    ...$window,
                    'hold_expires_at' => $data->confirm ? null : $policy->holdExpiresAt($now),
                    'confirmed_at' => $data->confirm ? $now : null,
                    'currency' => $lot['currency'],
                    'quoted_amount' => $quote['amount'],
                    'tariff_version_id' => $quote['tariff_version_id'],
                    'pricing' => $quote['pricing'],
                    'notes' => $data->notes,
                    'idempotency_key' => $data->idempotencyKey,
                    'created_by' => $actorUserId,
                ]);

                $this->vehicles->recordStayEvent($tenantId, $vehicle['id'], VehicleEventType::Reserved, $actorUserId, [
                    'reservation_id' => (string) $reservation->id,
                    'code' => $reservation->code,
                    'parking_lot_id' => $lot['id'],
                    'starts_at' => $reservation->starts_at->toIso8601String(),
                    'ends_at' => $reservation->ends_at->toIso8601String(),
                    'starts_local' => $reservation->starts_at->setTimezone($lot['timezone'])->format('Y-m-d H:i'),
                ]);

                // A window that already started holds its space right away.
                if ($data->confirm && $space !== null && $window['blocked_from'] <= $now) {
                    $this->sweeper->settleLot($tenantId, $lot['id'], $now);
                }

                return $reservation;
            });
        } catch (UniqueConstraintViolationException) {
            if ($data->idempotencyKey !== null && ($previous = $this->replay($tenantId, $data)) !== null) {
                return new ReservationOutcome($previous, true);
            }

            throw self::conflict();
        } catch (QueryException $exception) {
            // 23P01: an exclusion constraint saw an overlap the application missed.
            if ((string) $exception->getCode() !== '23P01') {
                throw $exception;
            }

            throw self::conflict();
        }

        $this->audit->record(
            action: 'reservation.created',
            resourceType: 'reservation',
            resourceId: (string) $reservation->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'code' => $reservation->code,
                'parking_lot_id' => $lot['id'],
                'plate' => $reservation->plate,
                'status' => $reservation->status->value,
                'zone_id' => $reservation->zone_id,
                'space_code' => $reservation->space_code,
                'starts_at' => $reservation->starts_at->toIso8601String(),
                'ends_at' => $reservation->ends_at->toIso8601String(),
                'quoted_amount' => $reservation->quoted_amount,
            ],
        );

        $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, $lot['id']));

        return new ReservationOutcome($reservation, false);
    }

    public static function conflict(): ReservationRuleViolation
    {
        return new ReservationRuleViolation('Otra reserva tomó ese lugar al mismo tiempo. Revisa la disponibilidad e intenta de nuevo.', 'RESERVATION_CONFLICT', 409);
    }

    /**
     * @return array{id: string, code: string, zone_id: string|null, type: SpaceType}|null
     */
    private function space(string $tenantId, string $lotId, ReservationData $data): ?array
    {
        if ($data->spaceId === null) {
            return null;
        }

        $space = $this->parking->space($tenantId, $lotId, $data->spaceId)
            ?? throw ValidationException::withMessages(['space_id' => 'El espacio no pertenece a este parqueadero.']);

        $inPool = array_filter($this->parking->reservableSpaces($tenantId, $lotId), fn (array $candidate): bool => $candidate['id'] === $space['id']);

        if ($inPool === []) {
            throw new ReservationRuleViolation("El espacio {$space['code']} no se puede reservar: no está marcado como reservable o no está en operación.", 'SPACE_NOT_RESERVABLE');
        }

        return $space;
    }

    /**
     * @return array{amount: int|null, tariff_version_id: string|null, pricing: array<string, mixed>}
     */
    private function quote(string $tenantId, string $lotId, VehicleType $type, ?string $zoneId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        $quote = $this->pricing->quote($tenantId, new PricingRequest(
            lotId: $lotId,
            vehicleType: $type,
            entryAt: $startsAt,
            exitAt: $endsAt,
            stayMinutes: intdiv($endsAt->getTimestamp() - $startsAt->getTimestamp(), 60),
            zoneId: $zoneId,
        ));

        return ['amount' => $quote->total, 'tariff_version_id' => $quote->tariff['version_id'] ?? null, 'pricing' => $quote->toArray()];
    }

    private function freeCode(string $tenantId): string
    {
        do {
            $code = ReservationCode::generate();
        } while (Reservation::query()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }

    private function replay(string $tenantId, ReservationData $data): ?Reservation
    {
        $previous = $this->lookup->byKey($tenantId, (string) $data->idempotencyKey);

        if ($previous === null) {
            return null;
        }

        if ((string) $previous->parking_lot_id !== $data->lotId || Plate::searchTerm($data->plate) !== $previous->plate) {
            throw new ReservationRuleViolation('La clave de idempotencia ya se usó para otra reserva.', 'IDEMPOTENCY_KEY_REUSED', 409);
        }

        return $previous;
    }
}
