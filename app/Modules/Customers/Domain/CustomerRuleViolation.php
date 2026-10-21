<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain;

use RuntimeException;

final class CustomerRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'CUSTOMER_RULE')
    {
        parent::__construct($message);
    }
}
