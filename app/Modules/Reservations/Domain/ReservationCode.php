<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Domain;

/**
 * Short code the customer shows on arrival ("R-7K3M9Q"). Letters and digits
 * that are easy to confuse when read aloud (0/O, 1/I/L) are left out.
 */
final class ReservationCode
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'R-'.$code;
    }

    public static function normalize(string $value): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return str_starts_with($code, 'R') && strlen($code) === 7 ? 'R-'.substr($code, 1) : $code;
    }
}
