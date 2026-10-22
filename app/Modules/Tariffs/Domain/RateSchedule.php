<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

use App\Modules\Shared\Domain\Currency;

/**
 * How a tariff turns minutes into money, in one shape that covers the usual
 * models:
 *
 * - Brackets are cumulative prices: "up to 15 min $2.000, up to 30 min
 *   $3.000, up to 60 min $5.000". The first bracket that covers the stay
 *   is the price.
 * - After the last bracket (or from minute zero when there are none) every
 *   started step adds its amount: "each additional hour $3.000" (hourly),
 *   "each 15 min $1.000" (fractional), "each day $20.000" (daily).
 * - An optional daily maximum caps each 24 hours counted from the entry.
 *
 * Progressive prices are brackets: first hour $5.000 and second $4.000 are
 * "up to 60 min $5.000, up to 120 min $9.000" followed by the additional
 * hour. Every amount is in minor units and must be chargeable in the
 * currency.
 */
final class RateSchedule
{
    public const DAY_MINUTES = 1440;

    private const MAX_BRACKETS = 12;

    private const MAX_AMOUNT = 1_000_000_000_000;

    /**
     * @param  list<array{up_to_minutes: int, amount: int}>  $brackets
     */
    private function __construct(
        public readonly array $brackets,
        public readonly int $stepMinutes,
        public readonly int $stepAmount,
        public readonly ?int $dailyMaxAmount,
    ) {}

    /**
     * @param  array<mixed>  $brackets
     */
    public static function create(array $brackets, int $stepMinutes, int $stepAmount, ?int $dailyMaxAmount, string $currency): self
    {
        if (count($brackets) > self::MAX_BRACKETS) {
            throw new TariffRuleViolation('Una tarifa admite como máximo '.self::MAX_BRACKETS.' tramos.', 'INVALID_RATES');
        }

        $normalized = [];
        $previousMinutes = 0;
        $previousAmount = 0;

        foreach (array_values($brackets) as $index => $bracket) {
            $minutes = is_array($bracket) ? ($bracket['up_to_minutes'] ?? null) : null;
            $amount = is_array($bracket) ? ($bracket['amount'] ?? null) : null;
            $position = $index + 1;

            if (! is_int($minutes) || ! is_int($amount)) {
                throw new TariffRuleViolation("El tramo {$position} necesita minutos y valor enteros.", 'INVALID_RATES');
            }

            if ($minutes <= $previousMinutes) {
                throw new TariffRuleViolation("Los minutos del tramo {$position} deben ser mayores que los del tramo anterior.", 'INVALID_RATES');
            }

            if ($minutes > 31 * self::DAY_MINUTES) {
                throw new TariffRuleViolation('Un tramo no puede superar 31 días.', 'INVALID_RATES');
            }

            // A longer stay never costs less than a shorter one.
            if ($amount < $previousAmount) {
                throw new TariffRuleViolation("El valor del tramo {$position} no puede ser menor que el del tramo anterior: los tramos son acumulados.", 'INVALID_RATES');
            }

            self::assertAmount($amount, $currency, "el tramo {$position}");

            $normalized[] = ['up_to_minutes' => $minutes, 'amount' => $amount];
            $previousMinutes = $minutes;
            $previousAmount = $amount;
        }

        if ($stepMinutes < 1 || $stepMinutes > 31 * self::DAY_MINUTES) {
            throw new TariffRuleViolation('La fracción adicional debe durar entre 1 minuto y 31 días.', 'INVALID_RATES');
        }

        self::assertAmount($stepAmount, $currency, 'la fracción adicional');

        if ($dailyMaxAmount !== null) {
            if ($dailyMaxAmount <= 0) {
                throw new TariffRuleViolation('El tope diario debe ser mayor que cero.', 'INVALID_RATES');
            }

            self::assertAmount($dailyMaxAmount, $currency, 'el tope diario');
        }

        return new self($normalized, $stepMinutes, $stepAmount, $dailyMaxAmount);
    }

    /**
     * Rebuilds a schedule validated when its version was saved. Versions are
     * immutable, so a stricter rule added later must not block the exit of
     * a stay priced by an older version.
     *
     * @param  list<array{up_to_minutes: int, amount: int}>  $brackets
     */
    public static function restore(array $brackets, int $stepMinutes, int $stepAmount, ?int $dailyMaxAmount): self
    {
        return new self(array_values($brackets), max(1, $stepMinutes), $stepAmount, $dailyMaxAmount);
    }

    /**
     * Price of a stay of the given chargeable minutes, with its explanation.
     *
     * @return array{amount: int, lines: list<PriceLine>}
     */
    public function charge(int $minutes): array
    {
        if ($minutes <= 0) {
            return ['amount' => 0, 'lines' => []];
        }

        if ($this->dailyMaxAmount === null) {
            return $this->continuous($minutes);
        }

        $days = intdiv($minutes, self::DAY_MINUTES);
        $rest = $minutes % self::DAY_MINUTES;
        $lines = [];
        $amount = 0;

        if ($days > 0) {
            $dayAmount = min($this->continuous(self::DAY_MINUTES)['amount'], $this->dailyMaxAmount);
            $amount += $days * $dayAmount;
            $lines[] = new PriceLine('day', $days === 1 ? '1 día completo' : "{$days} días completos", $days * $dayAmount, $days, $dayAmount);
        }

        if ($rest > 0) {
            $partial = $this->continuous($rest);

            if ($partial['amount'] > $this->dailyMaxAmount) {
                $amount += $this->dailyMaxAmount;
                $lines[] = new PriceLine('daily_cap', "Tope diario ({$rest} min)", $this->dailyMaxAmount);
            } else {
                $amount += $partial['amount'];
                array_push($lines, ...$partial['lines']);
            }
        }

        return ['amount' => $amount, 'lines' => $lines];
    }

    /**
     * @return array{brackets: list<array{up_to_minutes: int, amount: int}>, step_minutes: int, step_amount: int, daily_max_amount: int|null}
     */
    public function toArray(): array
    {
        return [
            'brackets' => $this->brackets,
            'step_minutes' => $this->stepMinutes,
            'step_amount' => $this->stepAmount,
            'daily_max_amount' => $this->dailyMaxAmount,
        ];
    }

    /**
     * Brackets, then started steps, without the daily maximum.
     *
     * @return array{amount: int, lines: list<PriceLine>}
     */
    private function continuous(int $minutes): array
    {
        foreach ($this->brackets as $bracket) {
            if ($minutes <= $bracket['up_to_minutes']) {
                return ['amount' => $bracket['amount'], 'lines' => [self::bracketLine($bracket)]];
            }
        }

        $last = $this->brackets === [] ? null : $this->brackets[array_key_last($this->brackets)];
        $covered = $last['up_to_minutes'] ?? 0;
        $steps = intdiv($minutes - $covered + $this->stepMinutes - 1, $this->stepMinutes);
        $lines = $last === null ? [] : [self::bracketLine($last)];
        $label = "{$steps} × ".self::minutesLabel($this->stepMinutes).($last === null ? '' : ' adicional');

        $lines[] = new PriceLine('step', $label, $steps * $this->stepAmount, $steps, $this->stepAmount);

        return ['amount' => ($last['amount'] ?? 0) + $steps * $this->stepAmount, 'lines' => $lines];
    }

    /**
     * @param  array{up_to_minutes: int, amount: int}  $bracket
     */
    private static function bracketLine(array $bracket): PriceLine
    {
        return new PriceLine('bracket', 'Hasta '.self::minutesLabel($bracket['up_to_minutes']), $bracket['amount']);
    }

    private static function minutesLabel(int $minutes): string
    {
        if ($minutes % self::DAY_MINUTES === 0) {
            $days = intdiv($minutes, self::DAY_MINUTES);

            return $days === 1 ? '1 día' : "{$days} días";
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hora' : "{$hours} horas";
        }

        return "{$minutes} min";
    }

    private static function assertAmount(int $amount, string $currency, string $what): void
    {
        if ($amount < 0 || $amount > self::MAX_AMOUNT) {
            throw new TariffRuleViolation("El valor de {$what} no es válido.", 'INVALID_RATES');
        }

        if (! Currency::isChargeable($amount, $currency)) {
            throw new TariffRuleViolation("El valor de {$what} no se puede cobrar en {$currency}: usa unidades enteras de la moneda.", 'INVALID_RATES');
        }
    }
}
