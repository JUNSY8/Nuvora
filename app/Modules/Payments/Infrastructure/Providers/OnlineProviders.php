<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Domain\Gateway\OnlinePaymentProvider;
use App\Modules\Payments\Domain\PaymentRuleViolation;

/**
 * The online provider adapters Nuvora ships. Adding one means writing its
 * adapter and listing it here; nothing else knows provider names.
 */
final class OnlineProviders
{
    /** @var array<string, OnlinePaymentProvider> */
    private array $providers = [];

    public function __construct(WompiProvider $wompi, PayUProvider $payu)
    {
        foreach ([$wompi, $payu] as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    public function for(string $key): OnlinePaymentProvider
    {
        return $this->providers[$key] ?? throw new PaymentRuleViolation('La pasarela no está disponible.', 'PROVIDER_UNKNOWN');
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return list<OnlinePaymentProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
