<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

/**
 * Plan codes are compared in upper case, with inner whitespace turned into
 * hyphens ("mensual carros" becomes MENSUAL-CARROS).
 */
final class PlanCode
{
    public static function normalize(string $value): string
    {
        $code = mb_strtoupper(trim($value));
        $code = (string) preg_replace('/\s+/', '-', $code);

        if ($code === '' || mb_strlen($code) > 40 || preg_match('/^[A-Z0-9][A-Z0-9_.-]*$/', $code) !== 1) {
            throw new MembershipRuleViolation('El código solo admite letras, números, guiones, puntos y guiones bajos.', 'INVALID_CODE');
        }

        return $code;
    }
}
