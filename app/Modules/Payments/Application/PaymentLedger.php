<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sums of what was collected per payable: received payments minus their
 * refunds. Pending and failed payments do not count.
 */
final class PaymentLedger
{
    public function paid(string $tenantId, PayableType $type, string $payableId): int
    {
        return (int) self::collected($tenantId, $type)
            ->where('payable_id', $payableId)
            ->sum(DB::raw('amount - refunded_amount'));
    }

    /**
     * @param  list<string>  $payableIds
     * @return array<string, int>
     */
    public function paidMany(string $tenantId, PayableType $type, array $payableIds): array
    {
        if ($payableIds === []) {
            return [];
        }

        return self::collected($tenantId, $type)
            ->whereIn('payable_id', $payableIds)
            ->groupBy('payable_id')
            ->selectRaw('payable_id, sum(amount - refunded_amount) as paid')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->payable_id => (int) $row->paid])
            ->all();
    }

    /**
     * @return Builder<Payment>
     */
    public static function collected(string $tenantId, PayableType $type): Builder
    {
        return Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('payable_type', $type->value)
            ->whereIn('status', PaymentStatus::collectedValues());
    }
}
