<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentMethod;

/**
 * A payment to record. Without an amount it pays the whole outstanding
 * balance; the tendered amount applies to cash only.
 */
final class PaymentData
{
    public function __construct(
        public readonly PayableType $payableType,
        public readonly string $payableId,
        public readonly PaymentMethod $method,
        public readonly ?int $amount = null,
        public readonly ?int $tendered = null,
        public readonly ?string $reference = null,
        public readonly ?string $notes = null,
    ) {}
}
