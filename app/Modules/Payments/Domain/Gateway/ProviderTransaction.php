<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\ProviderStatus;

/**
 * A provider transaction in normalized form. The amount is in the
 * currency's minor unit, like every amount in Nuvora.
 */
final class ProviderTransaction
{
    /**
     * @param  array<string, mixed>  $data  adapter details to keep (order ids...)
     */
    public function __construct(
        public readonly string $reference,
        public readonly ?string $transactionId,
        public readonly ProviderStatus $status,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $methodType = null,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {}

    /**
     * Nuvora's method for the provider's payment type, for the history and
     * the totals per method.
     */
    public function method(): PaymentMethod
    {
        $type = strtoupper((string) $this->methodType);

        return match (true) {
            str_contains($type, 'CARD') => PaymentMethod::Card,
            in_array($type, ['PSE', 'NEQUI', 'BANCOLOMBIA_TRANSFER', 'BANCOLOMBIA_QR', 'DAVIPLATA', 'ACH', 'BANK_REFERENCED'], true) => PaymentMethod::Transfer,
            default => PaymentMethod::Other,
        };
    }
}
