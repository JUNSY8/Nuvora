<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Modules\Shared\Domain\Currency;

/**
 * Cash handed over at the drawer and the change returned. Only cash takes
 * a tendered amount, and it can never be less than what is charged.
 */
final class CashTender
{
    private function __construct(
        public readonly ?int $tendered,
        public readonly ?int $change,
    ) {}

    public static function for(PaymentMethod $method, int $amount, ?int $tendered, string $currency): self
    {
        if ($method !== PaymentMethod::Cash) {
            return new self(null, null);
        }

        $tendered ??= $amount;

        if ($tendered < 0 || ! Currency::isChargeable($tendered, $currency)) {
            throw new PaymentRuleViolation('El efectivo recibido no es un valor válido para la moneda.', 'INVALID_AMOUNT');
        }

        if ($tendered < $amount) {
            throw new PaymentRuleViolation('El efectivo recibido es menor que el valor a cobrar.', 'INSUFFICIENT_TENDER');
        }

        return new self($tendered, $tendered - $amount);
    }
}
