<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentRuleViolation;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\ReceiptSeries;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentReconciliation;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;
use App\Modules\Payments\Infrastructure\ReceiptNumbers;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash closing (cuadre de caja). It takes every payment a cashier received
 * and every refund they gave since their last closing, adds them up per
 * method and compares the cash the drawer should hold with what was
 * counted. Each movement belongs to one closing only: the rows are locked
 * and stamped in the same transaction.
 *
 * A cashier closes their own cash with payments.create; closing someone
 * else's takes payments.reconcile.
 */
final class CashReconciliation
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ReceiptNumbers $numbers,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * What a closing would take now, per currency.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(string $actorUserId, string $tenantId, string $cashierId): array
    {
        $this->assertCanClose($actorUserId, $tenantId, $cashierId);

        $payments = $this->payments($tenantId, $cashierId)->get();
        $refunds = $this->refunds($tenantId, $cashierId)->get();
        $currencies = $payments->pluck('currency')->merge($refunds->pluck('currency'))->unique()->sort()->values();

        return $currencies->map(fn (string $currency): array => self::summary(
            $currency,
            $payments->where('currency', $currency),
            $refunds->where('currency', $currency),
        ))->all();
    }

    public function close(string $actorUserId, string $tenantId, string $cashierId, string $currency, int $countedCash, ?string $notes): PaymentReconciliation
    {
        $this->assertCanClose($actorUserId, $tenantId, $cashierId);

        if ($countedCash < 0 || ! Currency::isChargeable($countedCash, $currency)) {
            throw new PaymentRuleViolation('El efectivo contado no es un valor válido para la moneda.', 'INVALID_AMOUNT');
        }

        $reconciliation = DB::transaction(function () use ($actorUserId, $tenantId, $cashierId, $currency, $countedCash, $notes): PaymentReconciliation {
            $payments = $this->payments($tenantId, $cashierId)->where('currency', $currency)->lockForUpdate()->get();
            $refunds = $this->refunds($tenantId, $cashierId)->where('currency', $currency)->lockForUpdate()->get();

            if ($payments->isEmpty() && $refunds->isEmpty()) {
                throw new PaymentRuleViolation('No hay movimientos pendientes de cierre para este cajero.', 'NOTHING_TO_RECONCILE');
            }

            $summary = self::summary($currency, $payments, $refunds);

            $reconciliation = PaymentReconciliation::query()->create([
                'tenant_id' => $tenantId,
                'number' => $this->numbers->next($tenantId, ReceiptSeries::Reconciliation),
                'cashier_id' => $cashierId,
                'closed_by' => $actorUserId,
                'currency' => $currency,
                'first_movement_at' => $summary['first_movement_at'],
                'closed_at' => now(),
                'payments_count' => $summary['payments_count'],
                'refunds_count' => $summary['refunds_count'],
                'totals' => $summary['by_method'],
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => $countedCash,
                'difference' => $countedCash - $summary['expected_cash'],
                'notes' => CollectPayment::clean($notes, 500),
            ]);

            Payment::query()->where('tenant_id', $tenantId)->whereIn('id', $payments->pluck('id'))->update(['reconciliation_id' => $reconciliation->id]);
            PaymentRefund::query()->where('tenant_id', $tenantId)->whereIn('id', $refunds->pluck('id'))->update(['reconciliation_id' => $reconciliation->id]);

            return $reconciliation;
        });

        $this->audit->record(
            action: 'payment.reconciled',
            resourceType: 'payment_reconciliation',
            resourceId: (string) $reconciliation->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: [
                'number' => $reconciliation->number,
                'cashier_id' => $cashierId,
                'currency' => $currency,
                'expected_cash' => $reconciliation->expected_cash,
                'counted_cash' => $reconciliation->counted_cash,
                'difference' => $reconciliation->difference,
                'payments_count' => $reconciliation->payments_count,
                'refunds_count' => $reconciliation->refunds_count,
            ],
        );

        return $reconciliation;
    }

    /**
     * @return LengthAwarePaginator<int, PaymentReconciliation>
     */
    public function list(string $actorUserId, string $tenantId, ?string $cashierId, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_VIEW);

        return PaymentReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->when($cashierId !== null, fn (Builder $query) => $query->where('cashier_id', $cashierId))
            ->with(['cashier:id,name', 'closedBy:id,name'])
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    private function assertCanClose(string $actorUserId, string $tenantId, string $cashierId): void
    {
        if ($this->authorizer->allows($actorUserId, $tenantId, Permission::PAYMENTS_RECONCILE)) {
            return;
        }

        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_CREATE);

        if ($cashierId !== $actorUserId) {
            $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_RECONCILE);
        }
    }

    /**
     * @return Builder<Payment>
     */
    private function payments(string $tenantId, string $cashierId): Builder
    {
        return Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('received_by', $cashierId)
            ->whereNull('reconciliation_id')
            ->whereIn('status', PaymentStatus::collectedValues())
            ->orderBy('received_at');
    }

    /**
     * @return Builder<PaymentRefund>
     */
    private function refunds(string $tenantId, string $cashierId): Builder
    {
        return PaymentRefund::query()
            ->where('tenant_id', $tenantId)
            ->where('refunded_by', $cashierId)
            ->whereNull('reconciliation_id')
            ->orderBy('refunded_at');
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentRefund>  $refunds
     * @return array{currency: string, payments_count: int, refunds_count: int, first_movement_at: string|null, by_method: array<string, array{count: int, collected: int, refunded: int, net: int}>, expected_cash: int}
     */
    public static function summary(string $currency, Collection $payments, Collection $refunds): array
    {
        $byMethod = [];

        foreach (PaymentMethod::cases() as $method) {
            $in = $payments->filter(fn (Payment $payment): bool => $payment->method === $method);
            $out = $refunds->filter(fn (PaymentRefund $refund): bool => $refund->method === $method);
            $collected = (int) $in->sum('amount');
            $refunded = (int) $out->sum('amount');

            $byMethod[$method->value] = ['count' => $in->count(), 'collected' => $collected, 'refunded' => $refunded, 'net' => $collected - $refunded];
        }

        $first = collect([$payments->min('received_at'), $refunds->min('refunded_at')])->filter()->min();

        return [
            'currency' => $currency,
            'payments_count' => $payments->count(),
            'refunds_count' => $refunds->count(),
            'first_movement_at' => $first?->toIso8601String(),
            'by_method' => $byMethod,
            'expected_cash' => $byMethod[PaymentMethod::Cash->value]['net'],
        ];
    }
}
