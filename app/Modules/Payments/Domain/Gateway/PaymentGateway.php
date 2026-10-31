<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

use App\Modules\Payments\Domain\PaymentMethod;

/**
 * Port to whatever moves the money. Cash and manual payments go through the
 * offline gateway, which answers at once. Online providers (0.10) implement
 * the same port behind their own adapters and may answer "pending".
 */
interface PaymentGateway
{
    public function key(): string;

    public function supports(PaymentMethod $method): bool;

    public function charge(string $tenantId, PaymentMethod $method, int $amount, string $currency, ?string $reference): GatewayResult;

    public function refund(string $tenantId, PaymentMethod $method, int $amount, string $currency, ?string $providerReference): GatewayResult;
}
