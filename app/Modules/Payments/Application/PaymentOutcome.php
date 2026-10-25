<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Infrastructure\Persistence\Payment;

/**
 * Result of an idempotent payment operation: a replay returns the payment
 * the first request produced instead of collecting or refunding twice.
 */
final class PaymentOutcome
{
    public function __construct(
        public readonly Payment $payment,
        public readonly bool $replayed,
    ) {}
}
