<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Public contract of the Payments module for the modules that charge:
 * what each of their payables has collected. Callers authorize; this
 * contract does not check payment permissions.
 */
final class PaymentDirectory
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    /**
     * @param  array<string, int>  $dueById  what each payable charges in total
     * @return array<string, PaymentBalance>
     */
    public function balances(string $tenantId, PayableType $type, array $dueById): array
    {
        $paid = $this->ledger->paidMany($tenantId, $type, array_map('strval', array_keys($dueById)));
        $balances = [];

        foreach ($dueById as $id => $due) {
            $balances[(string) $id] = new PaymentBalance($due, $paid[(string) $id] ?? 0);
        }

        return $balances;
    }

    /**
     * Subquery of what a payable collected, correlated to the payable's id
     * column, for filters such as "closed sessions still owing".
     */
    public function paidAmountQuery(string $tenantId, PayableType $type, string $payableIdColumn): Builder
    {
        return DB::table('payments')
            ->selectRaw('coalesce(sum(amount - refunded_amount), 0)')
            ->where('payments.tenant_id', $tenantId)
            ->where('payments.payable_type', $type->value)
            ->whereIn('payments.status', PaymentStatus::collectedValues())
            ->whereColumn('payments.payable_id', $payableIdColumn);
    }

    /**
     * Money a lot took in a window: payments received minus refunds given.
     */
    public function collectedAt(string $tenantId, string $lotId, DateTimeInterface $from, ?DateTimeInterface $to = null): int
    {
        $received = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereIn('status', PaymentStatus::collectedValues())
            ->where('received_at', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('received_at', '<', $to))
            ->sum('amount');

        $refunded = PaymentRefund::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('payment_id', Payment::query()->where('tenant_id', $tenantId)->where('parking_lot_id', $lotId)->select('id'))
            ->where('refunded_at', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('refunded_at', '<', $to))
            ->sum('amount');

        return (int) $received - (int) $refunded;
    }
}
