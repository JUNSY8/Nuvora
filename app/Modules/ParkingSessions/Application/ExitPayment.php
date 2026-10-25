<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Payments\Domain\PaymentMethod;

/**
 * Payment taken at the exit desk, collected in the same transaction as the
 * exit: if the cash handed over does not cover the charge, the vehicle is
 * not checked out either.
 */
final class ExitPayment
{
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly ?int $tendered = null,
        public readonly ?string $reference = null,
    ) {}
}
