<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

use DateTimeImmutable;

final class WeeklySchedule
{
    /**
     * @param  array<int, OperatingDay>  $days  keyed by ISO day of week (1 = Monday)
     */
    private function __construct(public readonly array $days) {}

    /**
     * @param  list<OperatingDay>  $days
     */
    public static function fromDays(array $days): self
    {
        $indexed = [];

        foreach ($days as $day) {
            if (isset($indexed[$day->dayOfWeek])) {
                throw new ParkingRuleViolation('Cada día de la semana debe aparecer una sola vez.');
            }

            $indexed[$day->dayOfWeek] = $day;
        }

        if (count($indexed) !== 7) {
            throw new ParkingRuleViolation('El horario debe definir los siete días de la semana.');
        }

        ksort($indexed);

        return new self($indexed);
    }

    /**
     * Whether the lot stays open for the whole interval, both ends given in
     * the lot's local time. Overnight shifts continue into the next day, so a
     * Friday 18:00-02:00 shift covers Saturday 01:00.
     */
    public function covers(DateTimeImmutable $fromLocal, DateTimeImmutable $toLocal): bool
    {
        if ($toLocal <= $fromLocal) {
            return false;
        }

        $intervals = [];
        $day = $fromLocal->setTime(0, 0)->modify('-1 day');
        $last = $toLocal->setTime(0, 0);

        while ($day <= $last) {
            $schedule = $this->days[(int) $day->format('N')] ?? null;

            if ($schedule?->mode === OperatingDayMode::Open24h) {
                $intervals[] = [$day, $day->modify('+1 day')];
            } elseif ($schedule?->mode === OperatingDayMode::Scheduled) {
                [$openHour, $openMinute] = array_map('intval', explode(':', (string) $schedule->opensAt));
                [$closeHour, $closeMinute] = array_map('intval', explode(':', (string) $schedule->closesAt));
                $close = $day->setTime($closeHour, $closeMinute);

                $intervals[] = [$day->setTime($openHour, $openMinute), $schedule->crossesMidnight() ? $close->modify('+1 day') : $close];
            }

            $day = $day->modify('+1 day');
        }

        // Walk the open intervals in order; a gap before the end means closed time.
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $reached = $fromLocal;

        foreach ($intervals as [$start, $end]) {
            if ($start > $reached) {
                break;
            }

            if ($end > $reached) {
                $reached = $end;
            }

            if ($reached >= $toLocal) {
                return true;
            }
        }

        return false;
    }

    public static function alwaysOpen(): self
    {
        return self::fromDays(array_map(
            fn (int $day): OperatingDay => OperatingDay::create($day, OperatingDayMode::Open24h),
            range(1, 7),
        ));
    }
}
