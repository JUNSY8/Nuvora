<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

use DateTimeImmutable;

/**
 * What to charge on the hosted checkout. The reference is the payment id:
 * it is unique, and the provider echoes it back in every notification.
 */
final class CheckoutRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $returnUrl,
        public readonly string $notificationUrl,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?string $customerEmail = null,
    ) {}
}
