<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\Gateway\ProviderTransaction;
use App\Modules\Payments\Domain\Gateway\ProviderUnavailable;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;
use App\Modules\Payments\Infrastructure\Providers\OnlineProviders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asks providers about online payments and refunds that are still open.
 * Webhooks are the fast path; this is the safety net when one never
 * arrives. The scheduler runs it every five minutes; each payment backs
 * off (2, 4, 8... up to 60 minutes) and a link that expired without a
 * transaction is given up.
 */
final class ProviderSync
{
    /** Minutes after a link expires before it is given up, so a late approval can still arrive. */
    private const EXPIRY_MARGIN_MINUTES = 15;

    public function __construct(
        private readonly OnlineProviders $providers,
        private readonly ApplyProviderResult $apply,
        private readonly PayableSources $sources,
        private readonly PaymentLedger $ledger,
    ) {}

    /**
     * @return array{payments: int, changed: int, expired: int, refunds: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $counts = ['payments' => 0, 'changed' => 0, 'expired' => 0, 'refunds' => 0];

        $due = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('provider', '!=', 'offline')
            ->where('created_at', '<=', $now->subMinutes(2))
            ->with('providerAccount')
            ->orderBy('last_synced_at')
            ->limit(200)
            ->get()
            ->filter(fn (Payment $payment): bool => $payment->last_synced_at === null
                || $payment->last_synced_at->addMinutes(min(60, 2 ** $payment->sync_attempts)) <= $now);

        foreach ($due as $payment) {
            $counts['payments']++;
            $outcome = $this->payment($payment);

            if (in_array($outcome, [ApplyProviderResult::COMPLETED, ApplyProviderResult::FAILED], true)) {
                $counts['changed']++;
            } elseif ($outcome === null && $this->expire($payment, $now)) {
                $counts['expired']++;
            }
        }

        $refunds = PaymentRefund::query()
            ->where('status', RefundStatus::Pending->value)
            ->where('provider', '!=', 'offline')
            ->with('payment.providerAccount')
            ->limit(100)
            ->get();

        foreach ($refunds as $refund) {
            if ($this->refund($refund) !== RefundStatus::Pending) {
                $counts['refunds']++;
            }
        }

        return $counts;
    }

    /**
     * Asks the provider about one payment and applies the answer. Null when
     * the provider has no final answer (or could not be reached).
     */
    public function payment(Payment $payment): ?string
    {
        $account = $payment->providerAccount;

        if ($account === null) {
            return null;
        }

        $payment->forceFill(['sync_attempts' => $payment->sync_attempts + 1, 'last_synced_at' => now()])->save();

        try {
            $transaction = $this->providers->for($payment->provider)->transaction($account->toGatewayAccount(), (string) $payment->id, $payment->provider_reference);
        } catch (ProviderUnavailable) {
            return null;
        }

        if (! $transaction instanceof ProviderTransaction || ! $transaction->status->isFinal()) {
            return null;
        }

        return $this->apply->apply((string) $payment->tenant_id, (string) $payment->id, $transaction, 'sync');
    }

    private function expire(Payment $payment, CarbonImmutable $now): bool
    {
        if ($payment->expires_at === null || $payment->expires_at->addMinutes(self::EXPIRY_MARGIN_MINUTES) > $now) {
            return false;
        }

        return DB::transaction(function () use ($payment): bool {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PaymentStatus::Pending) {
                return false;
            }

            $locked->fill(['status' => PaymentStatus::Failed, 'failure_reason' => 'El enlace venció sin pago.'])->save();

            return true;
        });
    }

    public function refund(PaymentRefund $refund): RefundStatus
    {
        $payment = $refund->payment;
        $account = $payment?->providerAccount;

        if ($payment === null || $account === null) {
            return RefundStatus::Pending;
        }

        try {
            $answer = $this->providers->for($refund->provider)->refundStatus($account->toGatewayAccount(), (string) $payment->id, $payment->provider_data ?? [], $refund->provider_reference);
        } catch (ProviderUnavailable) {
            return RefundStatus::Pending;
        }

        if ($answer->status === RefundStatus::Completed) {
            $refund->forceFill(['status' => RefundStatus::Completed])->save();
        } elseif ($answer->status === RefundStatus::Failed) {
            $this->revert($refund, $answer->message ?? 'La pasarela rechazó el reembolso.');
        }

        return $answer->status;
    }

    /**
     * A refund the provider rejected gives its amount back to the payment,
     * and the payable owes that much less again.
     */
    public function revert(PaymentRefund $refund, string $reason): void
    {
        DB::transaction(function () use ($refund, $reason): void {
            $payment = Payment::query()->whereKey($refund->payment_id)->firstOrFail();
            $source = $this->sources->for($payment->payable_type);
            $payable = $source->lock((string) $payment->tenant_id, (string) $payment->payable_id);
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefundStatus::Pending) {
                return;
            }

            $locked->fill(['status' => RefundStatus::Failed, 'failure_reason' => mb_substr($reason, 0, 255)])->save();
            $refunded = max(0, $payment->refunded_amount - $locked->amount);
            $payment->fill(['refunded_amount' => $refunded, 'status' => PaymentStatus::forRefunded($payment->amount, $refunded)])->save();
            $source->settled((string) $payment->tenant_id, (string) $payment->payable_id, new PaymentBalance($payable->amountDue, $this->ledger->paid((string) $payment->tenant_id, $payment->payable_type, (string) $payment->payable_id)));
        });

        Log::warning('payment_refund.failed', ['refund_id' => (string) $refund->id, 'payment_id' => (string) $refund->payment_id]);
    }
}
