<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\Gateway\CheckoutRequest;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentRuleViolation;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentProviderAccount;
use App\Modules\Payments\Infrastructure\Providers\OnlineProviders;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Application\TenantDirectory;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment links. A cashier opens a pending online payment for a payable and
 * sends the customer a link; the public page sends the customer to the
 * provider's hosted checkout, and the webhook (or the sync job) confirms it.
 *
 * The link token is random, stored hashed for lookup and encrypted so staff
 * can copy the link again. While a link is pending, no second link opens
 * for the same payable, so two approvals cannot pay it twice.
 */
final class OnlineCheckouts
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantDirectory $tenants,
        private readonly PayableSources $sources,
        private readonly PaymentLedger $ledger,
        private readonly PaymentLookup $lookup,
        private readonly OnlineProviders $providers,
        private readonly ProviderSync $sync,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function create(string $actorUserId, string $tenantId, PayableType $type, string $payableId, string $accountId, ?int $amount, ?string $idempotencyKey): PaymentOutcome
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CREATE);

        if ($idempotencyKey !== null && ($previous = $this->lookup->byKey($tenantId, $idempotencyKey)) !== null) {
            return $this->replay($previous, $type, $payableId);
        }

        try {
            $payment = DB::transaction(function () use ($actorUserId, $tenantId, $type, $payableId, $accountId, $amount, $idempotencyKey): Payment {
                $account = PaymentProviderAccount::query()->where('tenant_id', $tenantId)->whereKey($accountId)->firstOrFail();

                if (! $account->isActive()) {
                    throw new PaymentRuleViolation('La cuenta de la pasarela está inactiva.', 'PROVIDER_INACTIVE');
                }

                $payable = $this->sources->for($type)->lock($tenantId, $payableId);

                if ($payable->notPayableReason !== null || $payable->amountDue <= 0 || $payable->currency === null) {
                    throw new PaymentRuleViolation($payable->notPayableReason ?? 'Este cobro no tiene valor a pagar.', 'NOTHING_DUE', 409);
                }

                $outstanding = (new PaymentBalance($payable->amountDue, $this->ledger->paid($tenantId, $type, $payable->id)))->outstanding();

                if ($outstanding === 0) {
                    throw new PaymentRuleViolation('Este cobro ya está pagado.', 'ALREADY_PAID', 409);
                }

                $open = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('payable_type', $type->value)
                    ->where('payable_id', $payable->id)
                    ->where('status', PaymentStatus::Pending->value)
                    ->where('expires_at', '>', now())
                    ->exists();

                if ($open) {
                    throw new PaymentRuleViolation('Ya hay un enlace de pago pendiente para este cobro. Anúlalo o espera a que venza.', 'PAYMENT_PENDING', 409);
                }

                $amount ??= $outstanding;

                if ($amount <= 0 || ! Currency::isChargeable($amount, $payable->currency)) {
                    throw new PaymentRuleViolation('El valor a cobrar no es válido para la moneda.', 'INVALID_AMOUNT');
                }

                if ($amount > $outstanding) {
                    throw new PaymentRuleViolation('El valor supera el saldo pendiente de este cobro.', 'AMOUNT_EXCEEDS_BALANCE');
                }

                $token = Str::random(48);

                return Payment::query()->create([
                    'tenant_id' => $tenantId,
                    'number' => null,
                    'payable_type' => $type,
                    'payable_id' => $payable->id,
                    'parking_lot_id' => $payable->lotId,
                    'customer_id' => $payable->customerId,
                    'description' => mb_substr($payable->description, 0, 160),
                    'plate' => $payable->plate,
                    'details' => $payable->details,
                    // The customer picks the method on the checkout; it is known once approved.
                    'method' => PaymentMethod::Other,
                    'provider' => $account->provider,
                    'provider_account_id' => $account->id,
                    'status' => PaymentStatus::Pending,
                    'amount' => $amount,
                    'refunded_amount' => 0,
                    'currency' => $payable->currency,
                    'idempotency_key' => $idempotencyKey,
                    'received_by' => null,
                    'requested_by' => $actorUserId,
                    'received_at' => now(),
                    'expires_at' => now()->addMinutes(self::linkMinutes()),
                    'checkout_token_hash' => hash('sha256', $token),
                    'checkout_token' => $token,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $idempotencyKey === null ? null : $this->lookup->byKey($tenantId, $idempotencyKey);

            if ($previous === null) {
                throw $exception;
            }

            return $this->replay($previous, $type, $payableId);
        }

        $this->audit->record('payment.link_created', 'payment', (string) $payment->id, $tenantId, $actorUserId, [
            'payable_type' => $payment->payable_type->value,
            'payable_id' => $payment->payable_id,
            'provider' => $payment->provider,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'expires_at' => $payment->expires_at?->toIso8601String(),
        ]);

        return new PaymentOutcome($payment, false);
    }

    /**
     * Gives up a pending link. The provider is asked first: if the customer
     * already paid, the payment is confirmed instead. A link the customer
     * keeps open can still be paid at the provider until it expires; such
     * a late approval is recorded (see ApplyProviderResult).
     */
    public function cancel(string $actorUserId, string $tenantId, string $paymentId): Payment
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CREATE);

        $payment = $this->lookup->find($tenantId, $paymentId);

        if (! $payment->isOnline() || $payment->status !== PaymentStatus::Pending) {
            throw new PaymentRuleViolation('Solo se anula un enlace de pago pendiente.', 'PAYMENT_NOT_PENDING', 409);
        }

        if ($this->sync->payment($payment) === ApplyProviderResult::COMPLETED) {
            throw new PaymentRuleViolation('La pasarela ya aprobó este pago; quedó registrado.', 'ALREADY_PAID', 409);
        }

        $cancelled = DB::transaction(function () use ($tenantId, $paymentId): bool {
            $locked = $this->lookup->find($tenantId, $paymentId, lock: true);

            if ($locked->status !== PaymentStatus::Pending) {
                return false;
            }

            $locked->fill(['status' => PaymentStatus::Failed, 'failure_reason' => 'Enlace anulado.'])->save();

            return true;
        });

        if ($cancelled) {
            $this->audit->record('payment.link_cancelled', 'payment', $paymentId, $tenantId, $actorUserId, ['provider' => $payment->provider, 'amount' => $payment->amount]);
        }

        return $this->lookup->find($tenantId, $paymentId);
    }

    /**
     * Asks the provider now, for a cashier waiting on a customer.
     */
    public function refresh(string $actorUserId, string $tenantId, string $paymentId): Payment
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_VIEW);

        $payment = $this->lookup->find($tenantId, $paymentId);

        if ($payment->isOnline() && $payment->status === PaymentStatus::Pending) {
            $this->sync->payment($payment->load('providerAccount'));
        }

        return $this->lookup->find($tenantId, $paymentId);
    }

    /**
     * What the public payment page shows. A pending link that is still valid
     * carries the provider's checkout; returning from the provider asks for
     * the result, at most every 15 seconds per payment.
     *
     * @return array<string, mixed>
     */
    public function publicView(string $token, bool $returning): array
    {
        $payment = Payment::query()->where('checkout_token_hash', hash('sha256', $token))->with('providerAccount')->firstOrFail();

        if ($returning && $payment->status === PaymentStatus::Pending && Cache::add("payments:public-sync:{$payment->id}", true, 15)) {
            $this->sync->payment($payment);
            $payment = $payment->fresh(['providerAccount']) ?? $payment;
        }

        $account = $payment->providerAccount;
        $expired = $payment->expires_at !== null && $payment->expires_at <= now();
        $payable = $payment->status === PaymentStatus::Pending && ! $expired && $account !== null && $account->isActive() && $this->stillOwed($payment);

        $checkout = $payable ? $this->providers->for($payment->provider)->checkout($account->toGatewayAccount(), new CheckoutRequest(
            reference: (string) $payment->id,
            amount: $payment->amount,
            currency: $payment->currency,
            description: $payment->description,
            returnUrl: self::linkUrl($token).'?volver=1',
            notificationUrl: ProviderAccounts::notificationUrl($account),
            expiresAt: $payment->expires_at ?? now()->addMinutes(self::linkMinutes())->toImmutable(),
        ))->toArray() : null;

        return [
            'tenant' => ['name' => $this->tenants->name((string) $payment->tenant_id)],
            'description' => $payment->description,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status === PaymentStatus::Pending && ($expired || ! $payable) ? 'expired' : $payment->status->value,
            'number' => $payment->number,
            'provider' => $account === null ? null : $this->providers->for($account->provider)->label(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'checkout' => $checkout,
        ];
    }

    private function stillOwed(Payment $payment): bool
    {
        $due = $this->sources->for($payment->payable_type)->lock((string) $payment->tenant_id, (string) $payment->payable_id)->amountDue;

        return (new PaymentBalance($due, $this->ledger->paid((string) $payment->tenant_id, $payment->payable_type, (string) $payment->payable_id)))->outstanding() >= $payment->amount;
    }

    private function replay(Payment $previous, PayableType $type, string $payableId): PaymentOutcome
    {
        if ($previous->payable_type !== $type || (string) $previous->payable_id !== $payableId) {
            throw new PaymentRuleViolation('La clave de idempotencia ya se usó para otro pago.', 'IDEMPOTENCY_KEY_REUSED', 409);
        }

        return new PaymentOutcome($previous, true);
    }

    public static function linkUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/pagar/'.$token;
    }

    public static function linkMinutes(): int
    {
        return max(5, (int) config('nuvora.payments.link_minutes', 60));
    }
}
