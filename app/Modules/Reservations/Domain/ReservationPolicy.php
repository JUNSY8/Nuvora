<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Domain;

use DateTimeImmutable;

/**
 * Reservation rules of one lot. The buffer separates consecutive
 * reservations of a space (cleaning, maneuvering, late exits); the hold is
 * how long an unconfirmed reservation keeps its capacity; the tolerance is
 * how late a vehicle may arrive before the reservation becomes a no-show.
 */
final class ReservationPolicy
{
    /** A vehicle may arrive this early even with a shorter buffer. */
    public const EARLY_ARRIVAL_MINUTES = 15;

    /** Clock skew accepted for a reservation that starts "now". */
    private const PAST_START_TOLERANCE_SECONDS = 300;

    private function __construct(
        public readonly bool $enabled,
        public readonly int $bufferMinutes,
        public readonly int $holdMinutes,
        public readonly int $arrivalToleranceMinutes,
        public readonly int $minDurationMinutes,
        public readonly int $maxDurationMinutes,
        public readonly int $maxAdvanceDays,
        public readonly bool $withinOperatingHours,
    ) {}

    public static function defaults(): self
    {
        return new self(true, 10, 15, 30, 30, 1440, 60, true);
    }

    public static function create(
        bool $enabled,
        int $bufferMinutes,
        int $holdMinutes,
        int $arrivalToleranceMinutes,
        int $minDurationMinutes,
        int $maxDurationMinutes,
        int $maxAdvanceDays,
        bool $withinOperatingHours,
    ): self {
        $checks = [
            [$bufferMinutes >= 0 && $bufferMinutes <= 240, 'El colchón entre reservas debe estar entre 0 y 240 minutos.'],
            [$holdMinutes >= 1 && $holdMinutes <= 1440, 'La retención de una reserva sin confirmar debe estar entre 1 minuto y 24 horas.'],
            [$arrivalToleranceMinutes >= 0 && $arrivalToleranceMinutes <= 720, 'La tolerancia de llegada debe estar entre 0 y 720 minutos.'],
            [$minDurationMinutes >= 15 && $minDurationMinutes <= 1440, 'La duración mínima debe estar entre 15 minutos y 24 horas.'],
            [$maxDurationMinutes >= $minDurationMinutes && $maxDurationMinutes <= 31 * 1440, 'La duración máxima debe ser mayor que la mínima y no superar 31 días.'],
            [$maxAdvanceDays >= 1 && $maxAdvanceDays <= 365, 'La anticipación máxima debe estar entre 1 y 365 días.'],
        ];

        foreach ($checks as [$valid, $message]) {
            if (! $valid) {
                throw new ReservationRuleViolation($message, 'INVALID_POLICY');
            }
        }

        return new self($enabled, $bufferMinutes, $holdMinutes, $arrivalToleranceMinutes, $minDurationMinutes, $maxDurationMinutes, $maxAdvanceDays, $withinOperatingHours);
    }

    /**
     * Validates the requested window and returns the times derived from this
     * policy, frozen into the reservation so a later policy change does not
     * move existing reservations.
     *
     * @return array{blocked_from: DateTimeImmutable, blocked_until: DateTimeImmutable, arrival_from: DateTimeImmutable, no_show_after: DateTimeImmutable}
     */
    public function window(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, DateTimeImmutable $now): array
    {
        if (! $this->enabled) {
            throw new ReservationRuleViolation('Este parqueadero no recibe reservas.', 'RESERVATIONS_DISABLED');
        }

        if ($endsAt <= $startsAt) {
            throw new ReservationRuleViolation('La reserva debe terminar después de empezar.', 'INVALID_WINDOW');
        }

        if ($startsAt->getTimestamp() < $now->getTimestamp() - self::PAST_START_TOLERANCE_SECONDS) {
            throw new ReservationRuleViolation('La reserva no puede empezar en el pasado.', 'INVALID_WINDOW');
        }

        $minutes = intdiv($endsAt->getTimestamp() - $startsAt->getTimestamp(), 60);

        if ($minutes < $this->minDurationMinutes || $minutes > $this->maxDurationMinutes) {
            throw new ReservationRuleViolation("La reserva debe durar entre {$this->minDurationMinutes} y {$this->maxDurationMinutes} minutos.", 'INVALID_WINDOW');
        }

        if ($startsAt > $now->modify("+{$this->maxAdvanceDays} days")) {
            throw new ReservationRuleViolation("Solo se reserva con hasta {$this->maxAdvanceDays} días de anticipación.", 'INVALID_WINDOW');
        }

        $early = max($this->bufferMinutes, self::EARLY_ARRIVAL_MINUTES);

        return [
            'blocked_from' => $startsAt->modify("-{$this->bufferMinutes} minutes"),
            'blocked_until' => $endsAt->modify("+{$this->bufferMinutes} minutes"),
            'arrival_from' => $startsAt->modify("-{$early} minutes"),
            'no_show_after' => $startsAt->modify("+{$this->arrivalToleranceMinutes} minutes"),
        ];
    }

    public function holdExpiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify("+{$this->holdMinutes} minutes");
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'buffer_minutes' => $this->bufferMinutes,
            'hold_minutes' => $this->holdMinutes,
            'arrival_tolerance_minutes' => $this->arrivalToleranceMinutes,
            'min_duration_minutes' => $this->minDurationMinutes,
            'max_duration_minutes' => $this->maxDurationMinutes,
            'max_advance_days' => $this->maxAdvanceDays,
            'within_operating_hours' => $this->withinOperatingHours,
        ];
    }
}
