<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Reservations\Domain\ReservationRuleViolation;
use App\Modules\Reservations\Domain\ReservationStatus;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Reservations\Infrastructure\ReservationLock;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Manual lifecycle steps: confirming a hold and cancelling. Both lock the
 * reservation and settle the lot first, so a hold that already expired is
 * reported as expired instead of being confirmed late.
 */
final class ChangeReservation
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ReservationLookup $lookup,
        private readonly ReservationSweeper $sweeper,
        private readonly ReservationLock $lock,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function confirm(string $actorUserId, string $tenantId, string $reservationId): Reservation
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::RESERVATIONS_MANAGE);

        $reservation = $this->transition($tenantId, $reservationId, ReservationStatus::Confirmed, function (Reservation $reservation): void {
            $reservation->fill(['status' => ReservationStatus::Confirmed, 'confirmed_at' => now(), 'hold_expires_at' => null])->save();
        });

        $this->record('reservation.confirmed', $reservation, $actorUserId, []);

        return $reservation;
    }

    public function cancel(string $actorUserId, string $tenantId, string $reservationId, string $reason): Reservation
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::RESERVATIONS_MANAGE);
        $reason = trim($reason);

        $reservation = $this->transition($tenantId, $reservationId, ReservationStatus::Cancelled, function (Reservation $reservation) use ($actorUserId, $reason): void {
            $reservation->fill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actorUserId,
                'cancel_reason' => $reason,
            ])->save();
            $this->sweeper->releaseSpace($reservation);
        });

        $this->record('reservation.cancelled', $reservation, $actorUserId, ['reason' => $reason]);

        return $reservation;
    }

    /**
     * @param  callable(Reservation): void  $apply
     */
    private function transition(string $tenantId, string $reservationId, ReservationStatus $target, callable $apply): Reservation
    {
        $reservation = $this->lookup->find($tenantId, $reservationId);

        return DB::transaction(function () use ($tenantId, $reservation, $target, $apply): Reservation {
            $this->lock->forLot((string) $reservation->parking_lot_id);
            $this->sweeper->settleLot($tenantId, (string) $reservation->parking_lot_id, now()->toImmutable());

            $reservation = $this->lookup->find($tenantId, (string) $reservation->id, lock: true);

            if (! $reservation->status->canTransitionTo($target)) {
                throw self::notChangeable($reservation);
            }

            $apply($reservation);

            return $reservation;
        });
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $action, Reservation $reservation, string $actorUserId, array $properties): void
    {
        $this->audit->record(
            action: $action,
            resourceType: 'reservation',
            resourceId: (string) $reservation->id,
            tenantId: (string) $reservation->tenant_id,
            actorUserId: $actorUserId,
            properties: ['code' => $reservation->code, 'plate' => $reservation->plate, ...$properties],
        );

        $this->live->touch(LiveUpdates::tenant((string) $reservation->tenant_id), LiveUpdates::lot((string) $reservation->tenant_id, (string) $reservation->parking_lot_id));
    }

    private static function notChangeable(Reservation $reservation): ReservationRuleViolation
    {
        $state = match ($reservation->status) {
            ReservationStatus::Expired => 'venció sin confirmarse',
            ReservationStatus::NoShow => 'quedó como no presentada',
            ReservationStatus::Cancelled => 'fue cancelada',
            ReservationStatus::Completed => 'ya terminó',
            ReservationStatus::Active => 'está en curso: el vehículo ya entró',
            ReservationStatus::Confirmed => 'ya está confirmada',
            ReservationStatus::Pending => 'está pendiente',
        };

        return new ReservationRuleViolation("La reserva {$reservation->code} {$state}.", 'RESERVATION_NOT_CHANGEABLE');
    }
}
