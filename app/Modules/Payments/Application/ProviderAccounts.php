<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\PaymentRuleViolation;
use App\Modules\Payments\Infrastructure\Persistence\PaymentProviderAccount;
use App\Modules\Payments\Infrastructure\Providers\OnlineProviders;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The tenant's accounts at online providers. Credentials are write-only:
 * they are encrypted at rest, a response only shows their last characters,
 * and an empty value on update keeps the stored one.
 */
final class ProviderAccounts
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly OnlineProviders $providers,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @return Collection<int, PaymentProviderAccount>
     */
    public function list(string $actorUserId, string $tenantId): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CONFIGURE);

        return PaymentProviderAccount::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
    }

    /**
     * Active accounts a cashier can send a payment link through.
     *
     * @return Collection<int, PaymentProviderAccount>
     */
    public function options(string $actorUserId, string $tenantId): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CREATE);

        return PaymentProviderAccount::query()->where('tenant_id', $tenantId)->where('status', 'active')->orderBy('name')->get();
    }

    /**
     * @param  array{provider: string, name: string, environment: string, status: string, credentials: array<string, string|null>}  $data
     */
    public function save(string $actorUserId, string $tenantId, ?string $accountId, array $data): PaymentProviderAccount
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CONFIGURE);

        $account = $accountId === null ? new PaymentProviderAccount(['tenant_id' => $tenantId, 'created_by' => $actorUserId]) : $this->find($tenantId, $accountId);

        if ($account->exists && $account->provider !== $data['provider']) {
            throw new PaymentRuleViolation('No se puede cambiar la pasarela de una cuenta; crea otra cuenta.', 'PROVIDER_IMMUTABLE');
        }

        $adapter = $this->providers->for($data['provider']);
        $stored = $account->exists ? ($account->credentials ?? []) : [];
        $credentials = [];
        $missing = [];

        foreach ($adapter->credentialKeys() as $key) {
            $value = trim((string) ($data['credentials'][$key] ?? ''));
            $credentials[$key] = $value !== '' ? $value : (string) ($stored[$key] ?? '');

            if ($credentials[$key] === '') {
                $missing["credentials.{$key}"] = 'Este dato de la pasarela es obligatorio.';
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $changed = array_keys(array_filter($credentials, fn (string $value, string $key): bool => ($stored[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        $account->fill([
            'provider' => $data['provider'],
            'name' => trim($data['name']),
            'environment' => $data['environment'],
            'status' => $data['status'],
            'credentials' => $credentials,
        ])->save();

        // Which credentials changed is audited; their values never are.
        $this->audit->record($accountId === null ? 'payment_provider.created' : 'payment_provider.updated', 'payment_provider_account', (string) $account->id, $tenantId, $actorUserId, [
            'provider' => $account->provider,
            'name' => $account->name,
            'environment' => $account->environment,
            'status' => $account->status,
            'credentials_changed' => $changed,
        ]);

        return $account;
    }

    public function find(string $tenantId, string $accountId): PaymentProviderAccount
    {
        return PaymentProviderAccount::query()->where('tenant_id', $tenantId)->whereKey($accountId)->firstOrFail();
    }

    /**
     * What a person may see of the credentials: the last four characters.
     *
     * @return array<string, string>
     */
    public function masked(PaymentProviderAccount $account): array
    {
        $masked = [];

        foreach ($this->providers->for($account->provider)->credentialKeys() as $key) {
            $value = (string) (($account->credentials ?? [])[$key] ?? '');
            $masked[$key] = $value === '' ? '' : '••••'.mb_substr($value, -4);
        }

        return $masked;
    }

    public static function notificationUrl(PaymentProviderAccount $account): string
    {
        return rtrim((string) config('app.url'), '/')."/api/v1/webhooks/payments/{$account->provider}/{$account->id}";
    }
}
