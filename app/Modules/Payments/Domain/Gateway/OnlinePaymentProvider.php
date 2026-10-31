<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

/**
 * Port of an online payment provider (Wompi, PayU...). Each adapter speaks
 * its provider's protocol and answers in these normalized shapes, so the
 * rest of Payments never sees a vendor's codes, signatures or URLs. The
 * customer pays on the provider's hosted checkout: Nuvora never handles
 * card numbers.
 */
interface OnlinePaymentProvider
{
    public function key(): string;

    public function label(): string;

    /**
     * Credential keys the tenant must enter, secret or not.
     *
     * @return list<string>
     */
    public function credentialKeys(): array;

    public function supportsPartialRefunds(): bool;

    /**
     * How to send the customer to the hosted checkout.
     */
    public function checkout(ProviderAccount $account, CheckoutRequest $request): Checkout;

    /**
     * Verifies a notification with the account's secret and normalizes it.
     *
     * @param  array<string, string>  $headers  lower-case names
     *
     * @throws InvalidProviderSignature
     */
    public function parseNotification(ProviderAccount $account, array $headers, string $body): ProviderNotification;

    /**
     * Asks the provider for the latest state of a payment, or null when it
     * has no transaction for that reference yet.
     */
    public function transaction(ProviderAccount $account, string $reference, ?string $transactionId): ?ProviderTransaction;

    /**
     * @param  array<string, mixed>  $providerData  what the adapter stored when the payment was confirmed
     */
    public function refund(ProviderAccount $account, string $transactionId, array $providerData, int $amount, string $currency, string $reason): ProviderRefund;

    /**
     * Follows up a refund the provider left pending.
     *
     * @param  array<string, mixed>  $providerData
     */
    public function refundStatus(ProviderAccount $account, string $reference, array $providerData, ?string $refundReference): ProviderRefund;
}
