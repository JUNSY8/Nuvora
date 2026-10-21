<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

use RuntimeException;

final class VehicleRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'VEHICLE_RULE')
    {
        parent::__construct($message);
    }
}
