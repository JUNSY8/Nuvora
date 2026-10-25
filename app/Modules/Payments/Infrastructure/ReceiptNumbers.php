<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure;

use App\Modules\Payments\Domain\ReceiptSeries;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next number of a series. It must run inside the
 * transaction that stores the receipt: the counter row stays locked until
 * that commit, so two cashiers never get the same number, and a rolled-back
 * receipt gives its number back.
 */
final class ReceiptNumbers
{
    public function next(string $tenantId, ReceiptSeries $series): string
    {
        DB::table('receipt_sequences')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'series' => $series->value,
            'last_number' => 0,
        ]);

        $row = DB::table('receipt_sequences')
            ->where('tenant_id', $tenantId)
            ->where('series', $series->value)
            ->lockForUpdate()
            ->first(['last_number']);

        $number = (int) ($row->last_number ?? 0) + 1;

        DB::table('receipt_sequences')
            ->where('tenant_id', $tenantId)
            ->where('series', $series->value)
            ->update(['last_number' => $number]);

        return $series->format($number);
    }
}
