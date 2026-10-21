<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

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

    public static function alwaysOpen(): self
    {
        return self::fromDays(array_map(
            fn (int $day): OperatingDay => OperatingDay::create($day, OperatingDayMode::Open24h),
            range(1, 7),
        ));
    }
}
