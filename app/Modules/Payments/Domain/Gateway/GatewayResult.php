<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

use App\Modules\Payments\Domain\PaymentStatus;

final class GatewayResult
{
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureReason = null,
    ) {}
}
