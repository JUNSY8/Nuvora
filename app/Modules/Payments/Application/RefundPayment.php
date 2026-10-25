<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\Gateway\ProviderUnavailable;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentRuleViolation;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\ReceiptSeries;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Infrastructure\Gateways\PaymentGateways;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;
use App\Modules\Payments\Infrastructure\Providers\OnlineProviders;
use App\Modules\Payments\Infrastructure\ReceiptNumbers;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gives back all or part of a payment. The payable is locked before the
 * payment (the same order collecting uses), the total refunded never
 * exceeds the payment, and the payable learns its new balance.
 *
 * - An offline payment goes back the way it came, from the drawer of whoever
 *   refunds it, so it counts in that person's next cash closing.
 * - An online payment goes back through its provider (channel "provider").
 *   The refund is reserved first and committed, then the provider is called
 *   outside the transaction: an answer that never comes leaves it pending
 *   for the sync job, and a rejection gives the amount back.
 * - An online payment can also be refunded by hand (channel "manual"): cash or
 *   a transfer made outside Nuvora, for providers without partial refunds.
 */
final class RefundPayment
{
    public const CHANNEL_PROVIDER = 'provider';

    public const CHANNEL_MANUAL = 'manual';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly PayableSources $sources,
        private readonly PaymentLedger $ledger,
        private readonly PaymentLookup $lookup,
        private readonly PaymentGateways $gateways,
        private readonly OnlineProviders $providers,
        private readonly ProviderSync $sync,
        private readonly ReceiptNumbers $numbers,
        private readonly RecordAuditEvent $audit,
        private readonly LiveUpdates $live,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $paymentId, ?int $amount, string $reason, ?string $idempotencyKey, ?string $channel = null, ?PaymentMethod $manualMethod = null): PaymentOutcome
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_REFUND);

        if ($idempotencyKey !== null && ($previous = $this->byKey($tenantId, $idempotencyKey)) !== null) {
            return $this->replay($tenantId, $previous, $paymentId);
        }

        try {
            [$payment, $refund] = DB::transaction(fn (): array => $this->reserve($actorUserId, $tenantId, $paymentId, $amount, $reason, $idempotencyKey, $channel, $manualMethod));
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $idempotencyKey === null ? null : $this->byKey($tenantId, $idempotencyKey);

            if ($previous === null) {
                throw $exception;
            }

            return $this->replay($tenantId, $previous, $paymentId);
        }

        if ($refund->status === RefundStatus::Pending) {
            $this->askProvider($payment, $refund);
            $refund->refresh();
            $payment = $this->lookup->find($tenantId, $paymentId);
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
                'provider' => $refund->provider,
                'refund_status' => $refund->status->value,
                'status' => $payment->status->value,
            ],
        );

        $keys = [LiveUpdates::tenant($tenantId)];

        if ($payment->parking_lot_id !== null) {
            $keys[] = LiveUpdates::lot($tenantId, (string) $payment->parking_lot_id);
        }

        $this->live->touch(...$keys);

        if ($refund->status === RefundStatus::Failed) {
            throw new PaymentRuleViolation('La pasarela rechazó el reembolso: '.($refund->failure_reason ?? 'sin detalle').' Puedes devolverlo a mano.', 'REFUND_FAILED', 502);
        }

        return new PaymentOutcome($payment, false);
    }

    /**
     * @return array{0: Payment, 1: PaymentRefund}
     */
    private function reserve(string $actorUserId, string $tenantId, string $paymentId, ?int $amount, string $reason, ?string $idempotencyKey, ?string $channel, ?PaymentMethod $manualMethod): array
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
        $throughProvider = $payment->isOnline() && $channel !== self::CHANNEL_MANUAL;

        if ($throughProvider) {
            $adapter = $this->providers->for($payment->provider);

            if (! $adapter->supportsPartialRefunds() && $amount !== $payment->amount - $payment->refunded_amount) {
                throw new PaymentRuleViolation("{$adapter->label()} solo devuelve el pago completo. Para un valor parcial, registra un reembolso manual.", 'PARTIAL_REFUND_UNSUPPORTED');
            }

            if ($payment->provider_reference === null) {
                throw new PaymentRuleViolation('El pago no tiene la referencia de la pasarela; regístralo como reembolso manual.', 'REFUND_FAILED', 409);
            }

            $refundStatus = RefundStatus::Pending;
            $provider = $payment->provider;
            $method = $payment->method;
            $providerReference = null;
        } else {
            $gateway = $this->gateways->byKey('offline');
            $method = $payment->isOnline() ? ($manualMethod ?? PaymentMethod::Transfer) : $payment->method;
            $result = $gateway->refund($tenantId, $method, $amount, $payment->currency, $payment->provider_reference);

            if ($result->status !== PaymentStatus::Completed) {
                throw new PaymentRuleViolation($result->failureReason ?? 'El medio de pago no aceptó el reembolso.', 'REFUND_FAILED', 502);
            }

            $refundStatus = RefundStatus::Completed;
            $provider = 'offline';
            $providerReference = $result->providerReference;
        }

        $refund = PaymentRefund::query()->create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'number' => $this->numbers->next($tenantId, ReceiptSeries::Refund),
            'amount' => $amount,
            'currency' => $payment->currency,
            'method' => $method,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'status' => $refundStatus,
            'reason' => mb_substr(trim($reason), 0, 255),
            'idempotency_key' => $idempotencyKey,
            'refunded_by' => $actorUserId,
            'refunded_at' => now(),
        ]);

        // A pending refund already counts: the money is promised back.
        $payment->fill([
            'refunded_amount' => $payment->refunded_amount + $amount,
            'status' => $status,
        ])->save();

        $source->settled($tenantId, $payable->id, new PaymentBalance($payable->amountDue, $this->ledger->paid($tenantId, $payable->type, $payable->id)));

        return [$payment, $refund];
    }

    private function askProvider(Payment $payment, PaymentRefund $refund): void
    {
        $account = $payment->providerAccount;

        if ($account === null) {
            return;
        }

        try {
            $answer = $this->providers->for($payment->provider)->refund($account->toGatewayAccount(), (string) $payment->provider_reference, $payment->provider_data ?? [], $refund->amount, $refund->currency, $refund->reason);
        } catch (ProviderUnavailable) {
            // Left pending: the sync job asks again.
            return;
        }

        if ($answer->status === RefundStatus::Failed) {
            $this->sync->revert($refund, $answer->message ?? 'La pasarela rechazó el reembolso.');

            return;
        }

        $refund->forceFill(['status' => $answer->status, 'provider_reference' => $answer->reference ?? $refund->provider_reference])->save();
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
