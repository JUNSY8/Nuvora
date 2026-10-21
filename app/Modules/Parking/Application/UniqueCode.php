<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Translates a duplicate code into a field error. The exists() check gives a
 * friendly answer; the unique index still decides when two requests race.
 * The write gets its own transaction (a savepoint when nested) so a failed
 * insert does not abort an enclosing PostgreSQL transaction.
 */
final class UniqueCode
{
    /**
     * @template T
     *
     * @param  Closure(): bool  $taken
     * @param  Closure(): T  $write
     * @return T
     */
    public static function guard(string $message, Closure $taken, Closure $write): mixed
    {
        if ($taken()) {
            throw self::conflict($message);
        }

        try {
            return DB::transaction(fn () => $write());
        } catch (UniqueConstraintViolationException) {
            throw self::conflict($message);
        }
    }

    private static function conflict(string $message): ValidationException
    {
        return ValidationException::withMessages(['code' => $message]);
    }
}
