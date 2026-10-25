<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Domain\Gateway\Checkout;
use App\Modules\Payments\Domain\Gateway\CheckoutRequest;
use App\Modules\Payments\Domain\Gateway\InvalidProviderSignature;
use App\Modules\Payments\Domain\Gateway\OnlinePaymentProvider;
use App\Modules\Payments\Domain\Gateway\ProviderAccount;
use App\Modules\Payments\Domain\Gateway\ProviderNotification;
use App\Modules\Payments\Domain\Gateway\ProviderRefund;
use App\Modules\Payments\Domain\Gateway\ProviderTransaction;
use App\Modules\Payments\Domain\ProviderStatus;
use App\Modules\Payments\Domain\RefundStatus;
use Carbon\CarbonImmutable;

/**
 * Wompi (Colombia): Web Checkout by redirect, events signed with the
 * events secret, transaction lookups and voids of card payments. Amounts
 * travel in cents, which for COP is Nuvora's minor unit.
 *
 * - Checkout: integrity signature = SHA256(reference + amount in cents +
 *   currency + expiration + integrity secret).
 * - Events: checksum = SHA256(values of signature.properties + timestamp +
 *   events secret).
 */
final class WompiProvider implements OnlinePaymentProvider
{
    private const CHECKOUT_URL = 'https://checkout.wompi.co/p/';

    public function key(): string
    {
        return 'wompi';
    }

    public function label(): string
    {
        return 'Wompi';
    }

    public function credentialKeys(): array
    {
        return ['public_key', 'private_key', 'events_secret', 'integrity_secret'];
    }

    public function supportsPartialRefunds(): bool
    {
        return false;
    }

    public function checkout(ProviderAccount $account, CheckoutRequest $request): Checkout
    {
        $expiration = CarbonImmutable::instance($request->expiresAt)->utc()->format('Y-m-d\TH:i:s.v\Z');
        $integrity = hash('sha256', $request->reference.$request->amount.$request->currency.$expiration.$account->credential('integrity_secret'));

        $fields = [
            'public-key' => $account->credential('public_key'),
            'currency' => $request->currency,
            'amount-in-cents' => (string) $request->amount,
            'reference' => $request->reference,
            'signature:integrity' => $integrity,
            'redirect-url' => $request->returnUrl,
            'expiration-time' => $expiration,
        ];

        if ($request->customerEmail !== null) {
            $fields['customer-data:email'] = $request->customerEmail;
        }

        return new Checkout(self::CHECKOUT_URL, 'GET', $fields);
    }

    public function parseNotification(ProviderAccount $account, array $headers, string $body): ProviderNotification
    {
        $event = json_decode($body, true);

        if (! is_array($event) || ! is_array($event['signature'] ?? null) || ! is_array($event['data'] ?? null)) {
            throw new InvalidProviderSignature('Evento de Wompi sin firma.');
        }

        $properties = $event['signature']['properties'] ?? [];
        $checksum = strtolower((string) ($event['signature']['checksum'] ?? $headers['x-event-checksum'] ?? ''));

        if (! is_array($properties) || $properties === [] || $checksum === '') {
            throw new InvalidProviderSignature('Evento de Wompi sin firma.');
        }

        $values = '';

        foreach ($properties as $property) {
            $values .= (string) data_get($event['data'], (string) $property, '');
        }

        $expected = hash('sha256', $values.($event['timestamp'] ?? '').$account->credential('events_secret'));

        if (! hash_equals($expected, $checksum)) {
            throw new InvalidProviderSignature('La firma del evento de Wompi no coincide.');
        }

        $transaction = is_array($event['data']['transaction'] ?? null) ? self::transactionFrom($event['data']['transaction']) : null;

        return new ProviderNotification(
            eventKey: $checksum,
            type: (string) ($event['event'] ?? 'unknown'),
            transaction: $transaction,
            payload: [
                'event' => $event['event'] ?? null,
                'environment' => $event['environment'] ?? null,
                'timestamp' => $event['timestamp'] ?? null,
                'transaction' => $transaction === null ? null : [
                    'id' => $transaction->transactionId,
                    'reference' => $transaction->reference,
                    'status' => $transaction->status->value,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'payment_method_type' => $transaction->methodType,
                ],
            ],
        );
    }

    public function transaction(ProviderAccount $account, string $reference, ?string $transactionId): ?ProviderTransaction
    {
        if ($transactionId !== null) {
            $response = ProviderHttp::send('Wompi', 'transaction', fn ($http) => $http->get($this->api($account, "/transactions/{$transactionId}")));

            return $response->successful() && is_array($response->json('data')) ? self::transactionFrom($response->json('data')) : null;
        }

        $response = ProviderHttp::send('Wompi', 'transactions', fn ($http) => $http
            ->withToken($account->credential('private_key'))
            ->get($this->api($account, '/transactions'), ['reference' => $reference]));

        $items = $response->successful() ? $response->json('data') : null;

        if (! is_array($items) || $items === []) {
            return null;
        }

        // An approved attempt wins over the declined ones of the same reference.
        $transactions = array_map(fn (array $item): ProviderTransaction => self::transactionFrom($item), array_values(array_filter($items, 'is_array')));
        usort($transactions, fn (ProviderTransaction $a, ProviderTransaction $b): int => (int) ($b->status === ProviderStatus::Approved) <=> (int) ($a->status === ProviderStatus::Approved));

        return $transactions[0] ?? null;
    }

    public function refund(ProviderAccount $account, string $transactionId, array $providerData, int $amount, string $currency, string $reason): ProviderRefund
    {
        $response = ProviderHttp::send('Wompi', 'void', fn ($http) => $http
            ->withToken($account->credential('private_key'))
            ->post($this->api($account, "/transactions/{$transactionId}/void")));

        if (! $response->successful()) {
            return new ProviderRefund(RefundStatus::Failed, null, (string) ($response->json('error.reason') ?? $response->json('error.type') ?? 'Wompi no aceptó la anulación.'));
        }

        $status = strtoupper((string) ($response->json('data.transaction.status') ?? $response->json('data.status') ?? ''));

        return new ProviderRefund($status === 'VOIDED' ? RefundStatus::Completed : RefundStatus::Pending, $transactionId);
    }

    public function refundStatus(ProviderAccount $account, string $reference, array $providerData, ?string $refundReference): ProviderRefund
    {
        $transaction = $this->transaction($account, $reference, $refundReference);

        return match ($transaction?->status) {
            ProviderStatus::Voided => new ProviderRefund(RefundStatus::Completed, $refundReference),
            ProviderStatus::Approved, ProviderStatus::Pending, null => new ProviderRefund(RefundStatus::Pending, $refundReference),
            default => new ProviderRefund(RefundStatus::Failed, $refundReference, 'Wompi no completó la anulación.'),
        };
    }

    private function api(ProviderAccount $account, string $path): string
    {
        return ($account->sandbox ? 'https://sandbox.wompi.co/v1' : 'https://production.wompi.co/v1').$path;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function transactionFrom(array $data): ProviderTransaction
    {
        $status = match (strtoupper((string) ($data['status'] ?? ''))) {
            'APPROVED' => ProviderStatus::Approved,
            'DECLINED' => ProviderStatus::Declined,
            'VOIDED' => ProviderStatus::Voided,
            'ERROR' => ProviderStatus::Error,
            default => ProviderStatus::Pending,
        };

        return new ProviderTransaction(
            reference: (string) ($data['reference'] ?? ''),
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            status: $status,
            amount: (int) ($data['amount_in_cents'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            methodType: isset($data['payment_method_type']) ? (string) $data['payment_method_type'] : null,
            message: isset($data['status_message']) ? mb_substr((string) $data['status_message'], 0, 200) : null,
        );
    }
}
