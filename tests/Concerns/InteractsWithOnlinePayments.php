<?php

namespace Tests\Concerns;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

trait InteractsWithOnlinePayments
{
    /** @var array<string, string> */
    protected array $wompiCredentials = [
        'public_key' => 'pub_test_nuvora1234',
        'private_key' => 'prv_test_nuvora5678',
        'events_secret' => 'test_events_secret_9012',
        'integrity_secret' => 'test_integrity_secret_3456',
    ];

    /** @var array<string, string> */
    protected array $payuCredentials = [
        'merchant_id' => '508029',
        'account_id' => '512321',
        'api_key' => '4Vj8eK4rloUd272L48hsrarnUA',
        'api_login' => 'pRRXKOl8ikMmt9u',
    ];

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createProviderAccount(User $owner, string $provider = 'wompi', array $overrides = []): string
    {
        return (string) $this->actingAs($owner)->postJson('/api/v1/payment-providers', [
            'provider' => $provider,
            'name' => $provider === 'wompi' ? 'Wompi principal' : 'PayU principal',
            'environment' => 'sandbox',
            'status' => 'active',
            'credentials' => $provider === 'wompi' ? $this->wompiCredentials : $this->payuCredentials,
            ...$overrides,
        ])->assertCreated()->json('data.account.id');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    protected function paymentLink(User $actor, string $payableType, string $payableId, string $accountId, ?int $amount = null, array $headers = []): TestResponse
    {
        $response = $this->actingAs($actor)->withHeaders($headers)->postJson('/api/v1/payments/checkouts', [
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'account_id' => $accountId,
            'amount' => $amount,
        ]);
        $this->flushHeaders();

        return $response;
    }

    /**
     * A Wompi transaction.updated event signed with the account's events secret.
     *
     * @return array<string, mixed>
     */
    protected function wompiEvent(string $reference, string $status, int $amount, string $transactionId = 'tx-wompi-1', int $timestamp = 1700000000, string $type = 'CARD'): array
    {
        return [
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => $transactionId,
                'status' => $status,
                'amount_in_cents' => $amount,
                'reference' => $reference,
                'currency' => 'COP',
                'payment_method_type' => $type,
            ]],
            'environment' => 'test',
            'signature' => [
                'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                'checksum' => hash('sha256', $transactionId.$status.$amount.$timestamp.$this->wompiCredentials['events_secret']),
            ],
            'timestamp' => $timestamp,
            'sent_at' => '2026-12-01T13:00:00.000Z',
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<JsonResponse>
     */
    protected function sendWompiEvent(string $accountId, array $event): TestResponse
    {
        return $this->postJson("/api/v1/webhooks/payments/wompi/{$accountId}", $event);
    }

    /**
     * A PayU confirmation signed with the account's API key.
     *
     * @return TestResponse<JsonResponse>
     */
    protected function sendPayUConfirmation(string $accountId, string $reference, string $value, string $state, string $transactionId = 'tx-payu-1'): TestResponse
    {
        $newValue = number_format((float) $value, 2, '.', '');
        $newValue = str_ends_with($newValue, '0') ? substr($newValue, 0, -1) : $newValue;
        $form = [
            'merchant_id' => $this->payuCredentials['merchant_id'],
            'reference_sale' => $reference,
            'reference_pol' => '880011',
            'transaction_id' => $transactionId,
            'value' => $value,
            'currency' => 'COP',
            'state_pol' => $state,
            'payment_method_type' => '2',
            'sign' => md5($this->payuCredentials['api_key'].'~'.$this->payuCredentials['merchant_id'].'~'.$reference.'~'.$newValue.'~COP~'.$state),
        ];

        return $this->call('POST', "/api/v1/webhooks/payments/payu/{$accountId}", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], http_build_query($form));
    }

    protected function checkoutToken(string $checkoutUrl): string
    {
        return basename((string) parse_url($checkoutUrl, PHP_URL_PATH));
    }
}
