<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

use App\Modules\Payments\Domain\RefundStatus;

final class ProviderRefund
{
    public function __construct(
        public readonly RefundStatus $status,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {}
}
