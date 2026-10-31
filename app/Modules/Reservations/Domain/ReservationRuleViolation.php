<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Domain;

use RuntimeException;

final class ReservationRuleViolation extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'RESERVATION_RULE',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
