<?php

declare(strict_types=1);

namespace App\Modules\Devices\Domain;

/**
 * Device codes are unique per tenant because Nuvora Edge reports events by
 * code (for example "entrada-01" becomes ENTRADA-01).
 */
final class DeviceCode
{
    public static function normalize(string $value): string
    {
        $code = mb_strtoupper(trim($value));
        $code = (string) preg_replace('/\s+/', '-', $code);

        if ($code === '' || mb_strlen($code) > 40 || preg_match('/^[A-Z0-9][A-Z0-9_.-]*$/', $code) !== 1) {
            throw new DeviceRuleViolation('El código solo admite letras, números, guiones, puntos y guiones bajos.', 'INVALID_CODE');
        }

        return $code;
    }
}
