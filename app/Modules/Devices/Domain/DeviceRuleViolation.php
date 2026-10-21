<?php

declare(strict_types=1);

namespace App\Modules\Devices\Domain;

use RuntimeException;

final class DeviceRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'DEVICE_RULE')
    {
        parent::__construct($message);
    }
}
