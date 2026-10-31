<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

/**
 * Codes identify tariffs and discounts in reports and on receipts. They are
 * compared in upper case, with inner whitespace turned into hyphens.
 */
final class TariffCode
{
    public static function normalize(string $value): string
    {
        $code = mb_strtoupper(trim($value));
        $code = (string) preg_replace('/\s+/', '-', $code);

        if ($code === '' || mb_strlen($code) > 40 || preg_match('/^[A-Z0-9][A-Z0-9_.-]*$/', $code) !== 1) {
            throw new TariffRuleViolation('El código solo admite letras, números, guiones, puntos y guiones bajos.', 'INVALID_CODE');
        }

        return $code;
    }
}
