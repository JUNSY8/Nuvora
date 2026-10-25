<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentRuleViolation;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\ReceiptSeries;
use App\Modules\Payments\Infrastructure\Gateways\PaymentGateways;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;
use App\Modules\Payments\Infrastructure\ReceiptNumbers;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gives back all or part of a payment, the same way it came in. The payable
 * is locked before the payment (the same order collecting uses), the total
 * refunded never exceeds the payment, and the payable learns its new
 * balance. A refund leaves the drawer of whoever makes it, so it counts in
 * that person's next cash closing.
 */
final class RefundPayment
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly PayableSources $sources,
        private readonly PaymentLedger $ledger,
        private readonly PaymentLookup $lookup,
        private readonly PaymentGateways $gateways,
        private readonly ReceiptNumbers $numbers,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $paymentId, ?int $amount, string $reason, ?string $idempotencyKey): PaymentOutcome
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_REFUND);

        if ($idempotencyKey !== null && ($previous = $this->byKey($tenantId, $idempotencyKey)) !== null) {
            return $this->replay($tenantId, $previous, $paymentId);
        }

        try {
            [$payment, $refund] = DB::transaction(fn (): array => $this->refund($actorUserId, $tenantId, $paymentId, $amount, $reason, $idempotencyKey));
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $idempotencyKey === null ? null : $this->byKey($tenantId, $idempotencyKey);

            if ($previous === null) {
                throw $exception;
            }

            return $this->replay($tenantId, $previous, $paymentId);
        }

        $this->audit->record(
            action: 'payment.refunded',
            resourceType: 'payment',
            resourceId: (string) $payment->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'number' => $payment->number,
                'refund_number' => $refund->number,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'reason' => $refund->reason,
                'status' => $payment->status->value,
            ],
        );

        $keys = [LiveUpdates::tenant($tenantId)];

        if ($payment->parking_lot_id !== null) {
            $keys[] = LiveUpdates::lot($tenantId, (string) $payment->parking_lot_id);
        }

        $this->live->touch(...$keys);

        return new PaymentOutcome($payment, false);
    }

    /**
     * @return array{0: Payment, 1: PaymentRefund}
     */
    private function refund(string $actorUserId, string $tenantId, string $paymentId, ?int $amount, string $reason, ?string $idempotencyKey): array
    {
        $unlocked = $this->lookup->find($tenantId, $paymentId);
        $source = $this->sources->for($unlocked->payable_type);
        $payable = $source->lock($tenantId, (string) $unlocked->payable_id);
        $payment = $this->lookup->find($tenantId, $paymentId, lock: true);

        $amount ??= $payment->amount - $payment->refunded_amount;

        if (! Currency::isChargeable($amount, $payment->currency)) {
            throw new PaymentRuleViolation('El valor a reembolsar no es válido para la moneda.', 'INVALID_AMOUNT');
        }

        $status = $payment->status->afterRefund($payment->amount, $payment->refunded_amount, $amount);
        $gateway = $this->gateways->byKey($payment->provider);
        $result = $gateway->refund($tenantId, $payment->method, $amount, $payment->currency, $payment->provider_reference);

        if ($result->status !== PaymentStatus::Completed) {
            throw new PaymentRuleViolation($result->failureReason ?? 'El medio de pago no aceptó el reembolso.', 'REFUND_FAILED', 502);
        }

        $refund = PaymentRefund::query()->create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'number' => $this->numbers->next($tenantId, ReceiptSeries::Refund),
            'amount' => $amount,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'provider_reference' => $result->providerReference,
            'reason' => mb_substr(trim($reason), 0, 255),
            'idempotency_key' => $idempotencyKey,
            'refunded_by' => $actorUserId,
            'refunded_at' => now(),
        ]);

        $payment->fill([
            'refunded_amount' => $payment->refunded_amount + $amount,
            'status' => $status,
        ])->save();

        $source->settled($tenantId, $payable->id, new PaymentBalance($payable->amountDue, $this->ledger->paid($tenantId, $payable->type, $payable->id)));

        return [$payment, $refund];
    }

    private function byKey(string $tenantId, string $key): ?PaymentRefund
    {
        return PaymentRefund::query()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
    }

    private function replay(string $tenantId, PaymentRefund $previous, string $paymentId): PaymentOutcome
    {
        if ((string) $previous->payment_id !== $paymentId) {
            throw new PaymentRuleViolation('La clave de idempotencia ya se usó para otro reembolso.', 'IDEMPOTENCY_KEY_REUSED', 409);
        }

        return new PaymentOutcome($this->lookup->find($tenantId, $paymentId), true);
    }
}
