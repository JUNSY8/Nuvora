<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

/**
 * Codes identify lots, zones, spaces and gates on signs and in reports. They
 * are compared in upper case, with inner whitespace turned into hyphens.
 */
final class ParkingCode
{
    public const MAX_LENGTH = 40;

    public static function normalize(string $value): string
    {
        $code = mb_strtoupper(trim($value));
        $code = (string) preg_replace('/\s+/', '-', $code);

        if ($code === '' || mb_strlen($code) > self::MAX_LENGTH || preg_match('/^[A-Z0-9][A-Z0-9_.-]*$/', $code) !== 1) {
            throw new ParkingRuleViolation('El código solo admite letras, números, guiones, puntos y guiones bajos.', 'INVALID_CODE');
        }

        return $code;
    }
}
