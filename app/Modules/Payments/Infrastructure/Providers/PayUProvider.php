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
use App\Modules\Shared\Domain\Currency;

/**
 * PayU Latam: WebCheckout by form post, confirmation page notifications,
 * the Reports API for lookups and the Payments API for refunds. PayU sends
 * amounts in major units with decimals; the adapter converts them.
 *
 * - Checkout: signature = MD5(apiKey~merchantId~referenceCode~amount~currency).
 * - Confirmation: sign = MD5(apiKey~merchant_id~reference_sale~new_value~currency~state_pol),
 *   where new_value keeps one decimal when the second one is zero (150.00 → 150.0).
 *   A 64-character sign is the SHA256 variant of the same string.
 */
final class PayUProvider implements OnlinePaymentProvider
{
    public function key(): string
    {
        return 'payu';
    }

    public function label(): string
    {
        return 'PayU';
    }

    public function credentialKeys(): array
    {
        return ['merchant_id', 'account_id', 'api_key', 'api_login'];
    }

    public function supportsPartialRefunds(): bool
    {
        return false;
    }

    public function checkout(ProviderAccount $account, CheckoutRequest $request): Checkout
    {
        $amount = self::decimal($request->amount, $request->currency);
        $merchantId = $account->credential('merchant_id');

        $fields = [
            'merchantId' => $merchantId,
            'accountId' => $account->credential('account_id'),
            'description' => mb_substr($request->description, 0, 255),
            'referenceCode' => $request->reference,
            'amount' => $amount,
            'tax' => '0',
            'taxReturnBase' => '0',
            'currency' => $request->currency,
            'signature' => md5($account->credential('api_key').'~'.$merchantId.'~'.$request->reference.'~'.$amount.'~'.$request->currency),
            'test' => $account->sandbox ? '1' : '0',
            'responseUrl' => $request->returnUrl,
            'confirmationUrl' => $request->notificationUrl,
        ];

        if ($request->customerEmail !== null) {
            $fields['buyerEmail'] = $request->customerEmail;
        }

        $url = $account->sandbox
            ? 'https://sandbox.checkout.payulatam.com/ppp-web-gateway-payu/'
            : 'https://checkout.payulatam.com/ppp-web-gateway-payu/';

        return new Checkout($url, 'POST', $fields);
    }

    public function parseNotification(ProviderAccount $account, array $headers, string $body): ProviderNotification
    {
        parse_str($body, $form);

        $reference = (string) ($form['reference_sale'] ?? '');
        $value = (string) ($form['value'] ?? '');
        $currency = strtoupper((string) ($form['currency'] ?? ''));
        $state = (string) ($form['state_pol'] ?? '');
        $sign = strtolower((string) ($form['sign'] ?? ''));

        if ($reference === '' || $value === '' || $sign === '') {
            throw new InvalidProviderSignature('Confirmación de PayU incompleta.');
        }

        $plain = $account->credential('api_key').'~'.($form['merchant_id'] ?? '').'~'.$reference.'~'.self::newValue($value).'~'.$currency.'~'.$state;
        $expected = strlen($sign) === 64 ? hash('sha256', $plain) : md5($plain);

        if (! hash_equals($expected, $sign) || (string) ($form['merchant_id'] ?? '') !== $account->credential('merchant_id')) {
            throw new InvalidProviderSignature('La firma de la confirmación de PayU no coincide.');
        }

        $transaction = new ProviderTransaction(
            reference: $reference,
            transactionId: isset($form['transaction_id']) ? (string) $form['transaction_id'] : null,
            status: self::state($state),
            amount: self::minor($value, $currency),
            currency: $currency,
            methodType: self::methodType((string) ($form['payment_method_type'] ?? '')),
            message: isset($form['response_message_pol']) ? mb_substr((string) $form['response_message_pol'], 0, 200) : null,
            data: ['order_id' => isset($form['reference_pol']) ? (string) $form['reference_pol'] : null],
        );

        return new ProviderNotification(
            eventKey: ($transaction->transactionId ?? $reference).'-'.$state,
            type: 'confirmation',
            transaction: $transaction,
            payload: [
                'reference_sale' => $reference,
                'reference_pol' => $form['reference_pol'] ?? null,
                'transaction_id' => $transaction->transactionId,
                'state_pol' => $state,
                'value' => $value,
                'currency' => $currency,
                'payment_method_type' => $form['payment_method_type'] ?? null,
            ],
        );
    }

    public function transaction(ProviderAccount $account, string $reference, ?string $transactionId): ?ProviderTransaction
    {
        $order = $this->order($account, $reference);

        if ($order === null) {
            return null;
        }

        $transactions = array_values(array_filter($order['transactions'] ?? [], fn ($item): bool => is_array($item) && strtoupper((string) ($item['type'] ?? '')) !== 'REFUND'));
        $latest = end($transactions) ?: null;
        $value = (string) data_get($order, 'additionalValues.TX_VALUE.value', data_get($order, 'additionalValues.entry.0.value.value', '0'));
        $currency = strtoupper((string) data_get($order, 'additionalValues.TX_VALUE.currency', data_get($order, 'currency', 'COP')));

        return new ProviderTransaction(
            reference: $reference,
            transactionId: $latest === null ? null : (string) ($latest['id'] ?? ''),
            status: self::state((string) data_get($latest, 'transactionResponse.state', $order['status'] ?? '')),
            amount: self::minor($value, $currency),
            currency: $currency,
            methodType: isset($latest['paymentMethod']) ? (string) $latest['paymentMethod'] : null,
            data: ['order_id' => isset($order['id']) ? (string) $order['id'] : null],
        );
    }

    public function refund(ProviderAccount $account, string $transactionId, array $providerData, int $amount, string $currency, string $reason): ProviderRefund
    {
        $response = ProviderHttp::send('PayU', 'refund', fn ($http) => $http->post($this->api($account, 'payments-api'), [
            'language' => 'es',
            'command' => 'SUBMIT_TRANSACTION',
            'merchant' => ['apiLogin' => $account->credential('api_login'), 'apiKey' => $account->credential('api_key')],
            'transaction' => [
                'order' => ['id' => $providerData['order_id'] ?? null],
                'type' => 'REFUND',
                'reason' => mb_substr($reason, 0, 255),
                'parentTransactionId' => $transactionId,
            ],
            'test' => $account->sandbox,
        ]));

        $state = strtoupper((string) $response->json('transactionResponse.state'));

        return match (true) {
            $response->json('code') !== 'SUCCESS' => new ProviderRefund(RefundStatus::Failed, null, (string) ($response->json('error') ?? 'PayU no aceptó el reembolso.')),
            $state === 'APPROVED' => new ProviderRefund(RefundStatus::Completed, (string) $response->json('transactionResponse.transactionId')),
            in_array($state, ['DECLINED', 'ERROR'], true) => new ProviderRefund(RefundStatus::Failed, null, (string) ($response->json('transactionResponse.responseMessage') ?? 'PayU rechazó el reembolso.')),
            // PayU reviews refunds; the sync job follows them up.
            default => new ProviderRefund(RefundStatus::Pending, (string) ($response->json('transactionResponse.transactionId') ?? '')),
        };
    }

    public function refundStatus(ProviderAccount $account, string $reference, array $providerData, ?string $refundReference): ProviderRefund
    {
        $order = $this->order($account, $reference);
        $refunds = array_values(array_filter($order['transactions'] ?? [], fn ($item): bool => is_array($item) && strtoupper((string) ($item['type'] ?? '')) === 'REFUND'));
        $refund = end($refunds) ?: null;
        $state = strtoupper((string) data_get($refund, 'transactionResponse.state', ''));

        return match ($state) {
            'APPROVED' => new ProviderRefund(RefundStatus::Completed, $refundReference),
            'DECLINED', 'ERROR' => new ProviderRefund(RefundStatus::Failed, $refundReference, 'PayU rechazó el reembolso.'),
            default => new ProviderRefund(RefundStatus::Pending, $refundReference),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function order(ProviderAccount $account, string $reference): ?array
    {
        $response = ProviderHttp::send('PayU', 'order', fn ($http) => $http->post($this->api($account, 'reports-api'), [
            'test' => $account->sandbox,
            'language' => 'es',
            'command' => 'ORDER_DETAIL_BY_REFERENCE_CODE',
            'merchant' => ['apiLogin' => $account->credential('api_login'), 'apiKey' => $account->credential('api_key')],
            'details' => ['referenceCode' => $reference],
        ]));

        $orders = $response->json('result.payload');

        return is_array($orders) && is_array($orders[0] ?? null) ? $orders[0] : null;
    }

    private function api(ProviderAccount $account, string $service): string
    {
        return ($account->sandbox ? 'https://sandbox.api.payulatam.com/' : 'https://api.payulatam.com/').$service.'/4.0/service.cgi';
    }

    private static function state(string $state): ProviderStatus
    {
        return match (strtoupper($state)) {
            '4', 'APPROVED' => ProviderStatus::Approved,
            '6', 'DECLINED' => ProviderStatus::Declined,
            '5', 'EXPIRED' => ProviderStatus::Expired,
            '7', 'PENDING', 'IN_PROGRESS', '' => ProviderStatus::Pending,
            default => ProviderStatus::Error,
        };
    }

    private static function methodType(string $code): ?string
    {
        return match ($code) {
            '2' => 'CREDIT_CARD',
            '4' => 'PSE',
            '5' => 'ACH',
            '6' => 'DEBIT_CARD',
            '7' => 'CASH',
            '8', '10' => 'BANK_REFERENCED',
            '' => null,
            default => 'OTHER',
        };
    }

    /**
     * Major units with the currency's decimals, as PayU expects them: 6000.00.
     */
    public static function decimal(int $minor, string $currency): string
    {
        $exponent = Currency::exponent($currency);

        return $exponent === 0 ? (string) $minor : number_format($minor / 10 ** $exponent, $exponent, '.', '');
    }

    public static function minor(string $value, string $currency): int
    {
        return (int) round(((float) $value) * 10 ** Currency::exponent($currency));
    }

    /**
     * The confirmation signs the value with one decimal when the second is
     * zero: 150.00 → 150.0, 150.26 → 150.26, 150.20 → 150.2.
     */
    public static function newValue(string $value): string
    {
        $formatted = number_format((float) $value, 2, '.', '');

        return str_ends_with($formatted, '0') ? substr($formatted, 0, -1) : $formatted;
    }
}
