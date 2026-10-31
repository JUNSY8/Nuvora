<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Parking\Application\ParkingSpaceAllocator;
use App\Modules\Reservations\Domain\ReservationStatus;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Reservations\Infrastructure\ReservationLock;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves reservations along with the clock:
 *
 * - an unconfirmed hold past its expiry becomes expired;
 * - a confirmed reservation whose vehicle did not arrive within the
 *   tolerance becomes a no-show;
 * - a confirmed space reservation holds its space (available → reserved)
 *   from the start of its window, buffer included, until the arrival or the
 *   no-show.
 *
 * The scheduler runs it every minute, and bookings and arrivals settle
 * their own lot first, so correctness does not depend on the scheduler
 * being on time.
 */
final class ReservationSweeper
{
    public function __construct(
        private readonly ParkingSpaceAllocator $spaces,
        private readonly ReservationLock $lock,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    /**
     * Settles every lot with due work. Returns how many reservations changed.
     */
    public function sweep(DateTimeImmutable $now): int
    {
        $lots = Reservation::query()
            ->where(fn ($query) => $query
                ->where(fn ($due) => $due->where('status', ReservationStatus::Pending->value)->where('hold_expires_at', '<=', $now))
                ->orWhere(fn ($due) => $due->where('status', ReservationStatus::Confirmed->value)->where('no_show_after', '<=', $now))
                ->orWhere(fn ($due) => $due->where('status', ReservationStatus::Confirmed->value)->whereNotNull('space_id')->where('blocked_from', '<=', $now)))
            ->select(['tenant_id', 'parking_lot_id'])
            ->distinct()
            ->get();

        $changed = 0;

        foreach ($lots as $lot) {
            $changed += DB::transaction(function () use ($lot, $now): int {
                $this->lock->forLot((string) $lot->parking_lot_id);

                return $this->settleLot((string) $lot->tenant_id, (string) $lot->parking_lot_id, $now);
            });
        }

        return $changed;
    }

    /**
     * Runs inside the caller's transaction, after taking the lot's lock.
     */
    public function settleLot(string $tenantId, string $lotId, DateTimeImmutable $now): int
    {
        $base = fn () => Reservation::query()->where('tenant_id', $tenantId)->where('parking_lot_id', $lotId);
        $changed = 0;

        foreach ($base()->where('status', ReservationStatus::Pending->value)->where('hold_expires_at', '<=', $now)->lockForUpdate()->get() as $reservation) {
            $changed += $this->end($reservation, ReservationStatus::Expired, $now);
        }

        foreach ($base()->where('status', ReservationStatus::Confirmed->value)->where('no_show_after', '<=', $now)->lockForUpdate()->get() as $reservation) {
            $changed += $this->end($reservation, ReservationStatus::NoShow, $now);
        }

        $starting = $base()
            ->where('status', ReservationStatus::Confirmed->value)
            ->whereNotNull('space_id')
            ->where('blocked_from', '<=', $now)
            ->get(['id', 'space_id']);

        foreach ($starting as $reservation) {
            if ($this->spaces->hold($tenantId, $lotId, (string) $reservation->space_id)) {
                $changed++;
            }
        }

        if ($changed > 0) {
            $this->live->touch(LiveUpdates::tenant($tenantId), LiveUpdates::lot($tenantId, $lotId));
        }

        return $changed;
    }

    /**
     * Gives the space back when a reservation ends without a stay. The space
     * may only be held by this reservation: overlapping holds are excluded.
     */
    public function releaseSpace(Reservation $reservation): void
    {
        if ($reservation->space_id !== null) {
            $this->spaces->releaseHold((string) $reservation->tenant_id, (string) $reservation->parking_lot_id, (string) $reservation->space_id);
        }
    }

    private function end(Reservation $reservation, ReservationStatus $status, DateTimeImmutable $now): int
    {
        $reservation->fill(['status' => $status, 'expired_at' => $now])->save();
        $this->releaseSpace($reservation);

        $this->audit->record(
            action: $status === ReservationStatus::Expired ? 'reservation.expired' : 'reservation.no_show',
            resourceType: 'reservation',
            resourceId: (string) $reservation->id,
            tenantId: (string) $reservation->tenant_id,
            actorUserId: null,
            properties: ['code' => $reservation->code, 'plate' => $reservation->plate],
        );

        return 1;
    }
}
