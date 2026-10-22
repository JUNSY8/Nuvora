<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

/**
 * Short code printed on the member's card ("M-4KQ7ZD"), without letters
 * and digits that are easy to confuse.
 */
final class MembershipCode
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'M-'.$code;
    }
}
