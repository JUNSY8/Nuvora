<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence;

use App\Modules\Payments\Domain\Gateway\ProviderAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's account at an online provider. Credentials are encrypted with
 * the application key at rest and hidden from serialization.
 */
#[Fillable(['tenant_id', 'provider', 'name', 'environment', 'status', 'credentials', 'created_by'])]
#[Hidden(['credentials'])]
class PaymentProviderAccount extends Model
{
    use HasUlids;

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function toGatewayAccount(): ProviderAccount
    {
        /** @var array<string, string> $credentials */
        $credentials = $this->credentials ?? [];

        return new ProviderAccount((string) $this->id, (string) $this->tenant_id, $this->provider, $this->environment === 'sandbox', $credentials);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }
}
