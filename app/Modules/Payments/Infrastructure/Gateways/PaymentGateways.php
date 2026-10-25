<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateways;

use App\Modules\Payments\Domain\Gateway\PaymentGateway;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentRuleViolation;

/**
 * Picks the gateway for a payment method. Every method is offline until
 * online providers (0.10) register their adapters ahead of it.
 */
final class PaymentGateways
{
    /** @var list<PaymentGateway> */
    private readonly array $gateways;

    public function __construct(OfflineGateway $offline)
    {
        $this->gateways = [$offline];
    }

    public function for(PaymentMethod $method): PaymentGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->supports($method)) {
                return $gateway;
            }
        }

        throw new PaymentRuleViolation('No hay un medio configurado para este tipo de pago.', 'METHOD_UNAVAILABLE');
    }

    public function byKey(string $key): PaymentGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->key() === $key) {
                return $gateway;
            }
        }

        throw new PaymentRuleViolation('El medio con el que se recibió el pago ya no está disponible.', 'METHOD_UNAVAILABLE');
    }
}
