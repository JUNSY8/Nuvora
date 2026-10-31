<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use RuntimeException;

final class PaymentRuleViolation extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'PAYMENT_RULE',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
