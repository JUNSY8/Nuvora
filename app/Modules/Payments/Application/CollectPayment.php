<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\CashTender;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentRuleViolation;
use App\Modules\Payments\Domain\ReceiptSeries;
use App\Modules\Payments\Infrastructure\Gateways\PaymentGateways;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\ReceiptNumbers;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records money received for a payable: a parking charge or a membership
 * period. The payable stays locked while its balance is read and the
 * payment stored, so two cashiers can never collect the same charge twice
 * or more than it owes. An Idempotency-Key makes a retried request return
 * the first payment.
 */
final class CollectPayment
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

    public function handle(string $actorUserId, string $tenantId, PaymentData $data, ?string $idempotencyKey): PaymentOutcome
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CREATE);

        if ($idempotencyKey !== null && ($previous = $this->lookup->byKey($tenantId, $idempotencyKey)) !== null) {
            return $this->replay($previous, $data);
        }

        try {
            $payment = DB::transaction(fn (): Payment => $this->record($actorUserId, $tenantId, $data, $idempotencyKey));
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request with the same key won the insert.
            $previous = $idempotencyKey === null ? null : $this->lookup->byKey($tenantId, $idempotencyKey);

            if ($previous === null) {
                throw $exception;
            }

            return $this->replay($previous, $data);
        }

        $this->audit->record(
            action: 'payment.collected',
            resourceType: 'payment',
            resourceId: (string) $payment->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'number' => $payment->number,
                'payable_type' => $payment->payable_type->value,
                'payable_id' => $payment->payable_id,
                'method' => $payment->method->value,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
            ],
        );

        $this->touch($tenantId, $payment);

        return new PaymentOutcome($payment, false);
    }

    private function record(string $actorUserId, string $tenantId, PaymentData $data, ?string $idempotencyKey): Payment
    {
        $source = $this->sources->for($data->payableType);
        $payable = $source->lock($tenantId, $data->payableId);

        if ($payable->notPayableReason !== null || $payable->amountDue <= 0 || $payable->currency === null) {
            throw new PaymentRuleViolation($payable->notPayableReason ?? 'Este cobro no tiene valor a pagar.', 'NOTHING_DUE', 409);
        }

        $balance = new PaymentBalance($payable->amountDue, $this->ledger->paid($tenantId, $payable->type, $payable->id));
        $outstanding = $balance->outstanding();

        if ($outstanding === 0) {
            throw new PaymentRuleViolation('Este cobro ya está pagado.', 'ALREADY_PAID', 409);
        }

        $amount = $data->amount ?? $outstanding;

        if ($amount <= 0 || ! Currency::isChargeable($amount, $payable->currency)) {
            throw new PaymentRuleViolation('El valor a cobrar no es válido para la moneda.', 'INVALID_AMOUNT');
        }

        if ($amount > $outstanding) {
            throw new PaymentRuleViolation('El valor supera el saldo pendiente de este cobro.', 'AMOUNT_EXCEEDS_BALANCE');
        }

        $tender = CashTender::for($data->method, $amount, $data->tendered, $payable->currency);
        $gateway = $this->gateways->for($data->method);
        $result = $gateway->charge($tenantId, $data->method, $amount, $payable->currency, $data->reference);

        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'number' => $this->numbers->next($tenantId, ReceiptSeries::Payment),
            'payable_type' => $payable->type,
            'payable_id' => $payable->id,
            'parking_lot_id' => $payable->lotId,
            'customer_id' => $payable->customerId,
            'description' => mb_substr($payable->description, 0, 160),
            'plate' => $payable->plate,
            'details' => $payable->details,
            'method' => $data->method,
            'provider' => $gateway->key(),
            'provider_reference' => $result->providerReference,
            'status' => $result->status,
            'amount' => $amount,
            'refunded_amount' => 0,
            'currency' => $payable->currency,
            'tendered_amount' => $tender->tendered,
            'change_amount' => $tender->change,
            'reference' => self::clean($data->reference, 100),
            'notes' => self::clean($data->notes, 500),
            'idempotency_key' => $idempotencyKey,
            'received_by' => $actorUserId,
            'received_at' => now(),
        ]);

        if ($payment->status->isCollected()) {
            $source->settled($tenantId, $payable->id, new PaymentBalance($payable->amountDue, $balance->paid + $amount));
        }

        return $payment;
    }

    private function replay(Payment $previous, PaymentData $data): PaymentOutcome
    {
        if ($previous->payable_type !== $data->payableType || (string) $previous->payable_id !== $data->payableId) {
            throw new PaymentRuleViolation('La clave de idempotencia ya se usó para otro pago.', 'IDEMPOTENCY_KEY_REUSED', 409);
        }

        return new PaymentOutcome($previous, true);
    }

    private function touch(string $tenantId, Payment $payment): void
    {
        $keys = [LiveUpdates::tenant($tenantId)];

        if ($payment->parking_lot_id !== null) {
            $keys[] = LiveUpdates::lot($tenantId, (string) $payment->parking_lot_id);
        }

        $this->live->touch(...$keys);
    }

    public static function clean(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
