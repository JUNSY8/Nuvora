<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Domain;

/**
 * Lifecycle of a parking stay. An open session ends once, either closed by
 * the vehicle's exit or cancelled as an operator mistake. Ended sessions are
 * immutable; corrections arrive later as controlled adjustments. Paid and
 * anomalous states arrive with payments (0.9) and access control.
 */
enum SessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::Closed, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }
}
