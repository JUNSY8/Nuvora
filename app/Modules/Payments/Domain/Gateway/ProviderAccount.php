<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

/**
 * A tenant's account at a provider, with its decrypted credentials. It is
 * handed to adapters only; it never reaches a response or a log.
 */
final class ProviderAccount
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $provider,
        public readonly bool $sandbox,
        public readonly array $credentials,
    ) {}

    public function credential(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'provider' => $this->provider, 'sandbox' => $this->sandbox];
    }
}
