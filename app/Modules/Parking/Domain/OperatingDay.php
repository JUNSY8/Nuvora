<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

/**
 * One day of a lot's weekly schedule, in the lot's local time. A scheduled day
 * whose closing time is earlier than its opening time runs past midnight.
 */
final class OperatingDay
{
    private function __construct(
        public readonly int $dayOfWeek,
        public readonly OperatingDayMode $mode,
        public readonly ?string $opensAt,
        public readonly ?string $closesAt,
    ) {}

    public static function create(int $dayOfWeek, OperatingDayMode $mode, ?string $opensAt = null, ?string $closesAt = null): self
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new ParkingRuleViolation('El día de la semana no es válido.');
        }

        if ($mode !== OperatingDayMode::Scheduled) {
            return new self($dayOfWeek, $mode, null, null);
        }

        $opensAt = self::normalizeTime($opensAt);
        $closesAt = self::normalizeTime($closesAt);

        if ($opensAt === null || $closesAt === null) {
            throw new ParkingRuleViolation('Un día con horario necesita hora de apertura y de cierre válidas.');
        }

        if ($opensAt === $closesAt) {
            throw new ParkingRuleViolation('La apertura y el cierre no pueden ser iguales. Usa 24 horas si no cierra.');
        }

        return new self($dayOfWeek, $mode, $opensAt, $closesAt);
    }

    public function crossesMidnight(): bool
    {
        return $this->mode === OperatingDayMode::Scheduled && $this->closesAt < $this->opensAt;
    }

    private static function normalizeTime(?string $value): ?string
    {
        if ($value === null || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1].':'.$matches[2];
    }
}
