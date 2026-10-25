<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentStatus;

/**
 * Filters of the payment history. Dates are local days of the tenant.
 */
final class PaymentFilters
{
    public function __construct(
        public readonly ?string $lotId = null,
        public readonly ?PaymentMethod $method = null,
        public readonly ?PaymentStatus $status = null,
        public readonly ?PayableType $payableType = null,
        public readonly ?string $payableId = null,
        public readonly ?string $receivedBy = null,
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
        public readonly ?string $search = null,
        public readonly ?bool $reconciled = null,
    ) {}
}
