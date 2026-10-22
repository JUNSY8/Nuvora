<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Domain;

/**
 * Lifecycle of a reservation:
 *
 *   pending ──► confirmed ──► active ──► completed
 *      │            │  ▲         │
 *      │            │  └─────────┘ (the entry was cancelled)
 *      ├► expired   ├► no_show
 *      └► cancelled └► cancelled
 *
 * A pending reservation is a hold that expires; a confirmed one becomes a
 * no-show when the vehicle does not arrive within the tolerance.
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NoShow = 'no_show';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::Expired],
            self::Confirmed => [self::Active, self::Cancelled, self::NoShow],
            self::Active => [self::Completed, self::Confirmed],
            self::Completed, self::Cancelled, self::Expired, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Statuses that take capacity and keep their space.
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Pending, self::Confirmed, self::Active];
    }

    /**
     * @return list<string>
     */
    public static function blockingValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::blocking());
    }
}
