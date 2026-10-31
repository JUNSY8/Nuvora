<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Reservations\Domain\ReservationStatus;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Reservations\Infrastructure\ReservationLock;
use DateTimeImmutable;

/**
 * Public contract of the Reservations module for parking sessions. Every
 * method runs inside the session's transaction, so the reservation and the
 * stay change together. Sessions authorize the caller; this contract does
 * not check reservation permissions.
 */
final class ReservationDirectory
{
    public function __construct(
        private readonly ReservationSweeper $sweeper,
        private readonly ReservationLock $lock,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * The confirmed reservation a vehicle arriving now can use, locked for
     * the check-in. A vehicle may arrive a little early and until the
     * tolerance after the start.
     *
     * @return array{id: string, code: string, zone_id: string|null, space_id: string|null, space_code: string|null}|null
     */
    public function arrival(string $tenantId, string $lotId, string $vehicleId, DateTimeImmutable $now): ?array
    {
        $this->lock->forLot($lotId);
        $this->sweeper->settleLot($tenantId, $lotId, $now);

        $reservation = Reservation::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->where('vehicle_id', $vehicleId)
            ->where('status', ReservationStatus::Confirmed->value)
            ->where('arrival_from', '<=', $now)
            ->where('no_show_after', '>', $now)
            ->orderBy('starts_at')
            ->lockForUpdate()
            ->first();

        return $reservation === null ? null : [
            'id' => (string) $reservation->id,
            'code' => $reservation->code,
            'zone_id' => $reservation->zone_id === null ? null : (string) $reservation->zone_id,
            'space_id' => $reservation->space_id === null ? null : (string) $reservation->space_id,
            'space_code' => $reservation->space_code,
        ];
    }

    public function checkIn(string $tenantId, string $reservationId, ?string $actorUserId, DateTimeImmutable $now): void
    {
        $this->move($tenantId, $reservationId, ReservationStatus::Active, ['checked_in_at' => $now], 'reservation.checked_in', $actorUserId);
    }

    public function complete(string $tenantId, string $reservationId, ?string $actorUserId, DateTimeImmutable $now): void
    {
        $this->move($tenantId, $reservationId, ReservationStatus::Completed, ['completed_at' => $now], 'reservation.completed', $actorUserId);
    }

    /**
     * The entry was cancelled as a mistake: the reservation waits for the
     * vehicle again. If its tolerance already passed, the next sweep marks it
     * as a no-show.
     */
    public function undoCheckIn(string $tenantId, string $reservationId, ?string $actorUserId): void
    {
        $this->move($tenantId, $reservationId, ReservationStatus::Confirmed, ['checked_in_at' => null], 'reservation.check_in_undone', $actorUserId);
    }

    /**
     * @param  list<string>  $reservationIds
     * @return array<string, array{id: string, code: string, status: string}>
     */
    public function summaries(string $tenantId, array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }

        return Reservation::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $reservationIds)
            ->get(['id', 'code', 'status'])
            ->mapWithKeys(fn (Reservation $reservation): array => [
                (string) $reservation->id => ['id' => (string) $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status->value],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function move(string $tenantId, string $reservationId, ReservationStatus $target, array $changes, string $action, ?string $actorUserId): void
    {
        $reservation = Reservation::query()->where('tenant_id', $tenantId)->whereKey($reservationId)->lockForUpdate()->first();

        // A reservation that moved on (for example, cancelled meanwhile) does not block the stay.
        if ($reservation === null || ! $reservation->status->canTransitionTo($target)) {
            return;
        }

        $reservation->fill(['status' => $target, ...$changes])->save();

        $this->audit->record(
            action: $action,
            resourceType: 'reservation',
            resourceId: (string) $reservation->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['code' => $reservation->code, 'plate' => $reservation->plate],
        );
    }
}
