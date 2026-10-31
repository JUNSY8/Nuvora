<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Parking\Domain\SpaceCompatibility;
use App\Modules\Reservations\Domain\ReservationCapacity;
use App\Modules\Reservations\Domain\ReservationRuleViolation;
use App\Modules\Reservations\Domain\ReservationStatus;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Answers what a window can still take. The pool of a lot (or zone) is its
 * reservable spaces in operation that accept the vehicle; the demand is the
 * peak of reservations holding capacity at one instant, buffers included.
 *
 * Every reservation counts against the lot. A zone reservation and a space
 * reservation also count against their zone. A space is never promised
 * twice for overlapping windows, and a vehicle cannot hold two overlapping
 * reservations in the same lot.
 */
final class ReservationCalendar
{
    public function __construct(private readonly ParkingInfrastructureDirectory $parking) {}

    /**
     * Reservations that hold capacity in [$from, $to) of the lot.
     *
     * @return Collection<int, Reservation>
     */
    public function blocking(string $tenantId, string $lotId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $exceptId = null): Collection
    {
        return Reservation::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereIn('status', ReservationStatus::blockingValues())
            ->where('blocked_from', '<', $to)
            ->where('blocked_until', '>', $from)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->get(['id', 'code', 'vehicle_id', 'zone_id', 'space_id', 'starts_at', 'ends_at', 'blocked_from', 'blocked_until']);
    }

    /**
     * Checks a new reservation against everything already booked.
     *
     * @param  array{id: string, code: string, zone_id: string|null}|null  $space
     */
    public function assertFits(
        string $tenantId,
        string $lotId,
        string $vehicleId,
        VehicleType $vehicleType,
        ?string $zoneId,
        ?array $space,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $blockedFrom,
        DateTimeImmutable $blockedUntil,
    ): void {
        $booked = $this->blocking($tenantId, $lotId, $blockedFrom, $blockedUntil);

        $sameVehicle = $booked->first(fn (Reservation $other): bool => (string) $other->vehicle_id === $vehicleId
            && $other->starts_at < $endsAt && $other->ends_at > $startsAt);

        if ($sameVehicle !== null) {
            throw new ReservationRuleViolation("El vehículo ya tiene la reserva {$sameVehicle->code} en ese horario.", 'VEHICLE_ALREADY_RESERVED', 409);
        }

        if ($space !== null && $booked->contains(fn (Reservation $other): bool => (string) $other->space_id === $space['id'])) {
            throw new ReservationRuleViolation("El espacio {$space['code']} ya está reservado en ese horario.", 'SPACE_ALREADY_RESERVED', 409);
        }

        $lotPool = $this->pool($tenantId, $lotId, null, $vehicleType);

        if ($lotPool === 0) {
            throw new ReservationRuleViolation('El parqueadero no tiene espacios reservables para este tipo de vehículo.', 'NO_RESERVABLE_SPACES');
        }

        if (ReservationCapacity::available($lotPool, self::intervals($booked), $blockedFrom, $blockedUntil) === 0) {
            throw new ReservationRuleViolation('No queda capacidad para reservar en ese horario.', 'FULLY_BOOKED', 409);
        }

        if ($zoneId === null) {
            return;
        }

        $zonePool = $this->pool($tenantId, $lotId, $zoneId, $vehicleType);
        $zoneBooked = $booked->filter(fn (Reservation $other): bool => (string) $other->zone_id === $zoneId);

        if (ReservationCapacity::available($zonePool, self::intervals($zoneBooked), $blockedFrom, $blockedUntil) === 0) {
            throw new ReservationRuleViolation('No queda capacidad en esa zona para ese horario.', 'FULLY_BOOKED', 409);
        }
    }

    public function pool(string $tenantId, string $lotId, ?string $zoneId, VehicleType $vehicleType): int
    {
        return count(array_filter(
            $this->parking->reservableSpaces($tenantId, $lotId, $zoneId),
            fn (array $space): bool => SpaceCompatibility::accepts($space['type'], $vehicleType),
        ));
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    public static function intervals(Collection $reservations): array
    {
        return $reservations->map(fn (Reservation $reservation): array => [$reservation->blocked_from, $reservation->blocked_until])->values()->all();
    }
}
