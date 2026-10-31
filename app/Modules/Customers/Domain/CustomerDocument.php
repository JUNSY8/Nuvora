<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain;

/**
 * An identity or tax document. The type is a tenant-defined code (CC, NIT,
 * PASSPORT...), not a hardcoded country list; the number is compared in its
 * normalized form so punctuation does not create duplicates.
 */
final class CustomerDocument
{
    private function __construct(
        public readonly string $type,
        public readonly string $number,
        public readonly string $normalized,
    ) {}

    public static function fromInput(?string $type, ?string $number): ?self
    {
        $type = $type === null ? '' : mb_strtoupper(trim($type));
        $number = $number === null ? '' : trim($number);

        if ($type === '' && $number === '') {
            return null;
        }

        if ($type === '' || $number === '') {
            throw new CustomerRuleViolation('Indica el tipo y el número del documento, o deja ambos vacíos.', 'INVALID_DOCUMENT');
        }

        if (preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', $type) !== 1) {
            throw new CustomerRuleViolation('El tipo de documento solo admite letras, números y guiones bajos.', 'INVALID_DOCUMENT');
        }

        $normalized = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($number));

        if ($normalized === '' || mb_strlen($number) > 40) {
            throw new CustomerRuleViolation('El número de documento no es válido.', 'INVALID_DOCUMENT');
        }

        return new self($type, $number, $normalized);
    }
}
