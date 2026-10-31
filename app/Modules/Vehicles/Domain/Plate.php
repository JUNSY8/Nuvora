<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

/**
 * Canonical license plate used for storage and comparison: uppercase letters
 * and digits without separators, so "abc-123", "ABC 123" and "ABC123" are the
 * same plate. Country rules only describe the format; they never reject a
 * plate, because foreign and special plates must still be registrable.
 */
final class Plate
{
    private function __construct(public readonly string $value) {}

    public static function fromInput(string $input): self
    {
        $value = mb_strtoupper(trim($input));
        $value = (string) preg_replace('/[\s\-.·_\/]+/u', '', $value);

        if (preg_match('/^[A-Z0-9]{2,12}$/', $value) !== 1) {
            throw new VehicleRuleViolation('La placa solo admite letras y números (entre 2 y 12).', 'INVALID_PLATE');
        }

        return new self($value);
    }

    /**
     * Best-effort canonical form for partial searches; never throws.
     */
    public static function searchTerm(string $input): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($input));
    }
}
