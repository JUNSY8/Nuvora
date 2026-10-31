<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

use RuntimeException;

final class ParkingRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'PARKING_RULE')
    {
        parent::__construct($message);
    }
}
