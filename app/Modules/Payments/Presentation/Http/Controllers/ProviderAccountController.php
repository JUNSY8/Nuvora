<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Controllers;

use App\Modules\Payments\Application\ProviderAccounts;
use App\Modules\Payments\Application\ProviderReconciliation;
use App\Modules\Payments\Infrastructure\Persistence\PaymentProviderAccount;
use App\Modules\Payments\Infrastructure\Providers\OnlineProviders;
use App\Modules\Payments\Presentation\Http\PaymentPayload;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProviderAccountController
{
    use ResolvesActor;

    public function index(Request $request, ProviderAccounts $accounts, OnlineProviders $providers, TenantContext $context): JsonResponse
    {
        $items = $accounts->list($this->actorId($request), (string) $context->id());

        return ApiResponse::success([
            'items' => $items->map(fn (PaymentProviderAccount $account): array => $this->payload($accounts, $account))->values()->all(),
            'providers' => array_map(fn ($provider): array => [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'credential_keys' => $provider->credentialKeys(),
                'partial_refunds' => $provider->supportsPartialRefunds(),
            ], $providers->all()),
        ], 200, $request);
    }

    public function options(Request $request, ProviderAccounts $accounts, OnlineProviders $providers, TenantContext $context): JsonResponse
    {
        $items = $accounts->options($this->actorId($request), (string) $context->id());

        return ApiResponse::success([
            'items' => $items->map(fn (PaymentProviderAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'provider' => $account->provider,
                'provider_label' => $providers->for($account->provider)->label(),
                'sandbox' => $account->environment === 'sandbox',
            ])->values()->all(),
        ], 200, $request);
    }

    public function store(Request $request, ProviderAccounts $accounts, TenantContext $context): JsonResponse
    {
        $account = $accounts->save($this->actorId($request), (string) $context->id(), null, $this->validated($request));

        return ApiResponse::success(['account' => $this->payload($accounts, $account)], 201, $request);
    }

    public function update(string $account, Request $request, ProviderAccounts $accounts, TenantContext $context): JsonResponse
    {
        $saved = $accounts->save($this->actorId($request), (string) $context->id(), $account, $this->validated($request));

        return ApiResponse::success(['account' => $this->payload($accounts, $saved)], 200, $request);
    }

    public function reconcile(string $account, Request $request, ProviderReconciliation $reconciliation, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        $result = $reconciliation->check($this->actorId($request), (string) $context->id(), $account, (string) $validated['from_date'], (string) $validated['to_date']);

        return ApiResponse::success(['reconciliation' => $result], 200, $request);
    }

    /**
     * @return array{provider: string, name: string, environment: string, status: string, credentials: array<string, string|null>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['wompi', 'payu'])],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'credentials' => ['present', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var array<string, string|null> $credentials */
        $credentials = $validated['credentials'];

        return [
            'provider' => (string) $validated['provider'],
            'name' => (string) $validated['name'],
            'environment' => (string) $validated['environment'],
            'status' => (string) $validated['status'],
            'credentials' => $credentials,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ProviderAccounts $accounts, PaymentProviderAccount $account): array
    {
        return PaymentPayload::providerAccount($account, $accounts->masked($account), ProviderAccounts::notificationUrl($account));
    }
}
