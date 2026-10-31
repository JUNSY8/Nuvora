<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\Gateway\ProviderTransaction;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\ProviderStatus;
use App\Modules\Payments\Domain\ReceiptSeries;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;
use App\Modules\Payments\Infrastructure\ReceiptNumbers;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use Illuminate\Support\Facades\DB;

/**
 * Applies what a provider says about an online payment, whether it came by
 * webhook, by the sync job or by a reconciliation. It is the only place
 * where an online payment changes status, so the three paths agree:
 *
 * - the payable is locked before the payment, like every payment write;
 * - an approval with another amount or currency is not accepted;
 * - an approval gives the payment its receipt number and settles the payable;
 * - a void of a received payment is recorded as a refund;
 * - repeated and out-of-order answers change nothing (PaymentStatus::afterProvider).
 */
final class ApplyProviderResult
{
    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const VOIDED = 'voided';

    public const UNCHANGED = 'unchanged';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public function __construct(
        private readonly PayableSources $sources,
        private readonly PaymentLedger $ledger,
        private readonly PaymentLookup $lookup,
        private readonly ReceiptNumbers $numbers,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function apply(string $tenantId, string $paymentId, ProviderTransaction $transaction, string $source): string
    {
        $outcome = DB::transaction(function () use ($tenantId, $paymentId, $transaction): string {
            $unlocked = $this->lookup->find($tenantId, $paymentId);
            $payableSource = $this->sources->for($unlocked->payable_type);
            $payable = $payableSource->lock($tenantId, (string) $unlocked->payable_id);
            $payment = $this->lookup->find($tenantId, $paymentId, lock: true);

            if ($transaction->status === ProviderStatus::Voided && $payment->status->isCollected()) {
                return $this->voided($payment, $payableSource, $payable->amountDue);
            }

            if ($transaction->status === ProviderStatus::Approved && ($transaction->amount !== $payment->amount || $transaction->currency !== $payment->currency)) {
                $payment->fill(['failure_reason' => mb_substr("La pasarela informó {$transaction->amount} {$transaction->currency} y se esperaba {$payment->amount} {$payment->currency}.", 0, 255)])->save();

                return self::AMOUNT_MISMATCH;
            }

            $next = $payment->status->afterProvider($transaction->status);

            if ($next === null) {
                return self::UNCHANGED;
            }

            $completed = $next === PaymentStatus::Completed;
            $payment->fill([
                'status' => $next,
                'provider_reference' => $transaction->transactionId ?? $payment->provider_reference,
                'provider_method' => $transaction->methodType ?? $payment->provider_method,
                'provider_data' => [...($payment->provider_data ?? []), ...array_filter($transaction->data, fn ($value): bool => $value !== null)],
                'failure_reason' => $completed ? null : mb_substr($transaction->message ?? 'Transacción '.$transaction->status->label().' en la pasarela.', 0, 255),
            ]);

            if ($completed) {
                $payment->fill([
                    'method' => $transaction->method(),
                    'number' => $payment->number ?? $this->numbers->next($tenantId, ReceiptSeries::Payment),
                    'received_at' => now(),
                ]);
            }

            $payment->save();

            if ($completed) {
                $payableSource->settled($tenantId, (string) $payment->payable_id, new PaymentBalance($payable->amountDue, $this->ledger->paid($tenantId, $payment->payable_type, (string) $payment->payable_id)));
            }

            return $completed ? self::COMPLETED : self::FAILED;
        });

        if (in_array($outcome, [self::COMPLETED, self::FAILED, self::VOIDED, self::AMOUNT_MISMATCH], true)) {
            $payment = $this->lookup->find($tenantId, $paymentId);

            $this->audit->record(
                action: match ($outcome) {
                    self::COMPLETED => 'payment.confirmed',
                    self::FAILED => 'payment.failed',
                    self::VOIDED => 'payment.voided',
                    default => 'payment.amount_mismatch',
                },
                resourceType: 'payment',
                resourceId: $paymentId,
                tenantId: $tenantId,
                actorUserId: null,
                properties: [
                    'number' => $payment->number,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'provider_status' => $transaction->status->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'source' => $source,
                ],
            );

            $keys = [LiveUpdates::tenant($tenantId)];

            if ($payment->parking_lot_id !== null) {
                $keys[] = LiveUpdates::lot($tenantId, (string) $payment->parking_lot_id);
            }

            $this->live->touch(...$keys);
        }

        return $outcome;
    }

    /**
     * The provider voided a received payment: a refund requested from
     * Nuvora completes, and a void made at the provider's dashboard is
     * recorded as a refund of what was left.
     */
    private function voided(Payment $payment, PayableSource $payableSource, int $amountDue): string
    {
        $pending = PaymentRefund::query()->where('payment_id', $payment->id)->where('status', RefundStatus::Pending->value)->get();

        if ($pending->isNotEmpty()) {
            PaymentRefund::query()->whereIn('id', $pending->pluck('id'))->update(['status' => RefundStatus::Completed->value]);

            return self::VOIDED;
        }

        $remaining = $payment->amount - $payment->refunded_amount;

        if ($remaining <= 0) {
            return self::UNCHANGED;
        }

        PaymentRefund::query()->create([
            'tenant_id' => $payment->tenant_id,
            'payment_id' => $payment->id,
            'number' => $this->numbers->next((string) $payment->tenant_id, ReceiptSeries::Refund),
            'amount' => $remaining,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'provider' => $payment->provider,
            'provider_reference' => $payment->provider_reference,
            'status' => RefundStatus::Completed,
            'reason' => 'Anulado en la pasarela.',
            'refunded_by' => null,
            'refunded_at' => now(),
        ]);

        $payment->fill(['refunded_amount' => $payment->amount, 'status' => PaymentStatus::Refunded])->save();
        $payableSource->settled((string) $payment->tenant_id, (string) $payment->payable_id, new PaymentBalance($amountDue, $this->ledger->paid((string) $payment->tenant_id, $payment->payable_type, (string) $payment->payable_id)));

        return self::VOIDED;
    }
}
