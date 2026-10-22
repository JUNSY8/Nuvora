<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Domain;

use DateTimeInterface;

/**
 * Length of a stay in whole minutes, counting a started minute as a full
 * one. Pricing (0.6) applies its own fractions and grace period from the
 * stored entry and exit timestamps; this value explains the stay.
 */
final class StayDuration
{
    public static function minutes(DateTimeInterface $entry, DateTimeInterface $exit): int
    {
        $seconds = $exit->getTimestamp() - $entry->getTimestamp();

        if ($seconds <= 0) {
            return 0;
        }

        return intdiv($seconds + 59, 60);
    }
}
