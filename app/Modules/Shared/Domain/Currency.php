<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

/**
 * Money is stored as integers in the currency's minor unit (ISO 4217
 * exponent): 5.000 COP is 500000. The step is the smallest amount charged in
 * practice, in minor units; Colombian pesos are charged in whole pesos, so
 * prices and computed discounts are multiples of 100 there. Payments (0.9)
 * and online providers use the same minor units.
 */
final class Currency
{
    /** @var array<string, array{exponent: int, step: int}> */
    private const KNOWN = [
        'COP' => ['exponent' => 2, 'step' => 100],
        'CLP' => ['exponent' => 0, 'step' => 1],
        'PYG' => ['exponent' => 0, 'step' => 1],
        'JPY' => ['exponent' => 0, 'step' => 1],
    ];

    public static function exponent(string $code): int
    {
        return self::KNOWN[strtoupper($code)]['exponent'] ?? 2;
    }

    public static function step(string $code): int
    {
        return self::KNOWN[strtoupper($code)]['step'] ?? 1;
    }

    public static function isChargeable(int $minorUnits, string $code): bool
    {
        return $minorUnits % self::step($code) === 0;
    }
}
