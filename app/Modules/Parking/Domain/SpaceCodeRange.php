<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

/**
 * Generates consecutive space codes such as A-001 … A-120 for bulk creation.
 */
final class SpaceCodeRange
{
    public const MAX_SPACES = 500;

    /**
     * @return list<string>
     */
    public static function generate(string $prefix, int $from, int $to, int $padding = 0): array
    {
        if ($from < 0 || $to < $from) {
            throw new ParkingRuleViolation('El rango de numeración no es válido.');
        }

        if ($to - $from + 1 > self::MAX_SPACES) {
            throw new ParkingRuleViolation('Puedes crear hasta '.self::MAX_SPACES.' espacios por operación.');
        }

        if ($padding < 0 || $padding > 6) {
            throw new ParkingRuleViolation('El relleno de ceros debe estar entre 0 y 6.');
        }

        $codes = [];

        foreach (range($from, $to) as $number) {
            $codes[] = ParkingCode::normalize($prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT));
        }

        return $codes;
    }
}
