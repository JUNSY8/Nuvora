<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

use DateTimeImmutable;

/**
 * How long one paid period of a membership lasts. Periods are whole local
 * days anchored to the day the membership started, so they never drift: a
 * monthly membership that starts on January 31 has periods Jan 31–Feb 27,
 * Feb 28–Mar 30, Mar 31–Apr 29... (the anchor day, clamped to short months).
 */
enum BillingPeriod: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /**
     * First and last day (inclusive) of period number $index (0 = first).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function period(DateTimeImmutable $anchor, int $index): array
    {
        return [$this->boundary($anchor, $index), $this->boundary($anchor, $index + 1)->modify('-1 day')];
    }

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'semanal',
            self::Monthly => 'mensual',
            self::Quarterly => 'trimestral',
            self::Yearly => 'anual',
        };
    }

    /**
     * Start of period $index: the anchor plus $index periods, with the anchor
     * day clamped to the length of the target month.
     */
    private function boundary(DateTimeImmutable $anchor, int $index): DateTimeImmutable
    {
        $anchor = $anchor->setTime(0, 0);

        if ($this === self::Weekly) {
            return $anchor->modify('+'.(7 * $index).' days');
        }

        $months = $index * match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };

        $target = $anchor->modify('first day of this month')->modify("+{$months} months");
        $day = min((int) $anchor->format('j'), (int) $target->format('t'));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);
    }
}
