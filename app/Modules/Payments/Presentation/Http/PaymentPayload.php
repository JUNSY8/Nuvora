<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Payments\Application\OnlineCheckouts;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentProviderAccount;
use App\Modules\Payments\Infrastructure\Persistence\PaymentReconciliation;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;

final class PaymentPayload
{
    /**
     * @param  iterable<Payment>  $payments
     * @return list<array<string, mixed>>
     */
    public static function many(string $tenantId, iterable $payments): array
    {
        $lotIds = [];

        foreach ($payments as $payment) {
            if ($payment->parking_lot_id !== null) {
                $lotIds[(string) $payment->parking_lot_id] = true;
            }
        }

        $lots = app(ParkingInfrastructureDirectory::class)->lotSummaries($tenantId, array_keys($lotIds));
        $items = [];

        foreach ($payments as $payment) {
            $items[] = self::payment($payment, $lots);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(string $tenantId, Payment $payment): array
    {
        $payment->loadMissing(['receivedBy:id,name', 'refunds.refundedBy:id,name', 'reconciliation:id,number', 'providerAccount:id,name,provider', 'requestedBy:id,name']);

        return [
            ...self::many($tenantId, [$payment])[0],
            'details' => $payment->details,
            'refunds' => $payment->refunds->map(fn (PaymentRefund $refund): array => [
                'id' => $refund->id,
                'number' => $refund->number,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'method' => $refund->method->value,
                'reason' => $refund->reason,
                'refunded_at' => $refund->refunded_at->toIso8601String(),
                'refunded_by' => $refund->refundedBy === null ? null : ['id' => $refund->refundedBy->id, 'name' => $refund->refundedBy->name],
                'status' => $refund->status->value,
                'provider' => $refund->provider,
                'failure_reason' => $refund->failure_reason,
            ])->values()->all(),
            'reconciliation' => $payment->reconciliation === null ? null : ['id' => $payment->reconciliation->id, 'number' => $payment->reconciliation->number],
            'requested_by' => $payment->requestedBy === null ? null : ['id' => $payment->requestedBy->id, 'name' => $payment->requestedBy->name],
            // Staff can copy a pending link again; the token is stored encrypted.
            'checkout_url' => $payment->isOnline() && $payment->status === PaymentStatus::Pending && $payment->checkout_token !== null ? OnlineCheckouts::linkUrl($payment->checkout_token) : null,
        ];
    }

    /**
     * @param  array<string, array{id: string, name: string, code: string}>  $lots
     * @return array<string, mixed>
     */
    private static function payment(Payment $payment, array $lots): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'status' => $payment->status->value,
            'payable' => ['type' => $payment->payable_type->value, 'id' => $payment->payable_id],
            'parking_lot' => $payment->parking_lot_id === null ? null : ($lots[(string) $payment->parking_lot_id] ?? ['id' => $payment->parking_lot_id, 'name' => null, 'code' => null]),
            'customer_id' => $payment->customer_id,
            'description' => $payment->description,
            'plate' => $payment->plate,
            'method' => $payment->method->value,
            'provider' => $payment->provider,
            'amount' => $payment->amount,
            'refunded_amount' => $payment->refunded_amount,
            'net_amount' => $payment->netAmount(),
            'currency' => $payment->currency,
            'tendered_amount' => $payment->tendered_amount,
            'change_amount' => $payment->change_amount,
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'received_at' => $payment->received_at->toIso8601String(),
            'received_by' => $payment->receivedBy === null ? null : ['id' => $payment->receivedBy->id, 'name' => $payment->receivedBy->name],
            'reconciled' => $payment->reconciliation_id !== null,
            'provider_account' => $payment->provider_account_id === null ? null : [
                'id' => $payment->provider_account_id,
                'name' => $payment->relationLoaded('providerAccount') ? $payment->providerAccount?->name : null,
            ],
            'provider_reference' => $payment->provider_reference,
            'provider_method' => $payment->provider_method,
            'failure_reason' => $payment->failure_reason,
            'expires_at' => $payment->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function reconciliation(PaymentReconciliation $reconciliation): array
    {
        $reconciliation->loadMissing(['cashier:id,name', 'closedBy:id,name']);

        return [
            'id' => $reconciliation->id,
            'number' => $reconciliation->number,
            'cashier' => $reconciliation->cashier === null ? null : ['id' => $reconciliation->cashier->id, 'name' => $reconciliation->cashier->name],
            'closed_by' => $reconciliation->closedBy === null ? null : ['id' => $reconciliation->closedBy->id, 'name' => $reconciliation->closedBy->name],
            'currency' => $reconciliation->currency,
            'first_movement_at' => $reconciliation->first_movement_at?->toIso8601String(),
            'closed_at' => $reconciliation->closed_at->toIso8601String(),
            'payments_count' => $reconciliation->payments_count,
            'refunds_count' => $reconciliation->refunds_count,
            'by_method' => $reconciliation->totals,
            'expected_cash' => $reconciliation->expected_cash,
            'counted_cash' => $reconciliation->counted_cash,
            'difference' => $reconciliation->difference,
            'notes' => $reconciliation->notes,
        ];
    }

    /**
     * @param  array<string, string>  $maskedCredentials
     * @return array<string, mixed>
     */
    public static function providerAccount(PaymentProviderAccount $account, array $maskedCredentials, string $notificationUrl): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'name' => $account->name,
            'environment' => $account->environment,
            'status' => $account->status,
            'credentials' => $maskedCredentials,
            'notification_url' => $notificationUrl,
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
