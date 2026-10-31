<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\ParkingRuleViolation;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Other modules (devices, later sessions) reference parking resources with
 * restrictive foreign keys. A delete blocked by one of them is a business
 * rule answer, not a server error. The statement runs in its own transaction
 * (a savepoint inside an outer one) because PostgreSQL aborts the enclosing
 * transaction when a constraint fails.
 */
final class ForeignKeyGuard
{
    public static function delete(Closure $delete, string $message, string $code): void
    {
        try {
            DB::transaction(fn () => $delete());
        } catch (QueryException $exception) {
            if (! str_starts_with((string) $exception->getCode(), '23')) {
                throw $exception;
            }

            throw new ParkingRuleViolation($message, $code);
        }
    }
}
