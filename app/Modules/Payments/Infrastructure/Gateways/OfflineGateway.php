<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateways;

use App\Modules\Payments\Domain\Gateway\GatewayResult;
use App\Modules\Payments\Domain\Gateway\PaymentGateway;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentStatus;

/**
 * Money that changes hands outside Nuvora: cash at the drawer, an external
 * card terminal, a bank transfer. The cashier already holds it when the
 * payment is recorded, so the gateway completes at once.
 */
final class OfflineGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'offline';
    }

    public function supports(PaymentMethod $method): bool
    {
        return true;
    }

    public function charge(string $tenantId, PaymentMethod $method, int $amount, string $currency, ?string $reference): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Completed);
    }

    public function refund(string $tenantId, PaymentMethod $method, int $amount, string $currency, ?string $providerReference): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Completed);
    }
}
