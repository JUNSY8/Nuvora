<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

use DateTimeInterface;

/**
 * A weekly period in the lot's local time: days of the week (ISO, 1 =
 * Monday) and a start and end time. An end earlier than the start runs past
 * midnight and belongs to the day it starts on: Friday 19:00-06:00 covers
 * Saturday 03:00. Equal start and end cover the whole day.
 */
final class TimeWindow
{
    /**
     * @param  list<int>  $days
     */
    private function __construct(
        public readonly array $days,
        public readonly string $start,
        public readonly string $end,
    ) {}

    /**
     * @param  array<mixed>  $days
     */
    public static function create(array $days, string $start, string $end): self
    {
        $normalized = [];

        foreach ($days as $day) {
            if (! is_int($day) || $day < 1 || $day > 7) {
                throw new TariffRuleViolation('Los días de una franja van de 1 (lunes) a 7 (domingo).', 'INVALID_WINDOW');
            }

            $normalized[$day] = $day;
        }

        if ($normalized === []) {
            throw new TariffRuleViolation('Cada franja debe incluir al menos un día.', 'INVALID_WINDOW');
        }

        ksort($normalized);

        return new self(array_values($normalized), self::time($start), self::time($end));
    }

    /**
     * @param  array{days?: mixed, start?: mixed, end?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        return self::create(
            is_array($data['days'] ?? null) ? $data['days'] : [],
            is_string($data['start'] ?? null) ? $data['start'] : '',
            is_string($data['end'] ?? null) ? $data['end'] : '',
        );
    }

    /**
     * @param  DateTimeInterface  $local  a moment already expressed in the lot's timezone
     */
    public function contains(DateTimeInterface $local): bool
    {
        $day = (int) $local->format('N');
        $time = $local->format('H:i');

        if ($this->start === $this->end) {
            return in_array($day, $this->days, true);
        }

        if ($this->start < $this->end) {
            return in_array($day, $this->days, true) && $time >= $this->start && $time < $this->end;
        }

        // Past midnight: the late part belongs to today, the early part to yesterday's window.
        $yesterday = $day === 1 ? 7 : $day - 1;

        return (in_array($day, $this->days, true) && $time >= $this->start)
            || (in_array($yesterday, $this->days, true) && $time < $this->end);
    }

    /**
     * @return array{days: list<int>, start: string, end: string}
     */
    public function toArray(): array
    {
        return ['days' => $this->days, 'start' => $this->start, 'end' => $this->end];
    }

    private static function time(string $value): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $value, $matches) !== 1) {
            throw new TariffRuleViolation('Las horas de una franja usan el formato HH:MM.', 'INVALID_WINDOW');
        }

        return $matches[1].':'.$matches[2];
    }
}
