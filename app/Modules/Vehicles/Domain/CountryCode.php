<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

/**
 * ISO 3166-1 alpha-2 country that issued the plate. Plates are unique per
 * tenant and country, since two countries can issue the same characters.
 */
final class CountryCode
{
    public static function normalize(string $value): string
    {
        $code = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new VehicleRuleViolation('El país debe ser un código ISO de dos letras.', 'INVALID_COUNTRY');
        }

        return $code;
    }
}
