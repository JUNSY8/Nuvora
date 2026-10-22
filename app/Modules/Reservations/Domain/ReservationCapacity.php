<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Domain;

use DateTimeImmutable;

/**
 * Capacity of a reservation pool over time. Two reservations that never
 * overlap share a space, so the demand of a window is the largest number of
 * reservations that coincide at some instant inside it, not how many touch
 * the window.
 */
final class ReservationCapacity
{
    /**
     * Highest number of the given intervals that overlap at one instant of
     * [$from, $to). Intervals are half-open: one ending when another starts
     * does not overlap it.
     *
     * @param  list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>  $intervals
     */
    public static function peak(array $intervals, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $events = [];

        foreach ($intervals as [$start, $end]) {
            $start = max($start, $from);
            $end = min($end, $to);

            if ($start < $end) {
                $events[] = [$start->getTimestamp(), 1];
                $events[] = [$end->getTimestamp(), -1];
            }
        }

        // At the same instant, ends are processed before starts.
        usort($events, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $current = 0;
        $peak = 0;

        foreach ($events as [, $delta]) {
            $current += $delta;
            $peak = max($peak, $current);
        }

        return $peak;
    }

    /**
     * @param  list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>  $intervals
     */
    public static function available(int $pool, array $intervals, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(0, $pool - self::peak($intervals, $from, $to));
    }
}
