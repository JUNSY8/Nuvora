<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Application\TenantDirectory;
use App\Modules\Tenancy\Domain\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payment history of the tenant, newest first, with the totals of the same
 * filters so the cashier sees what the list adds up to.
 */
final class ListPayments
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantDirectory $tenants,
        private readonly PaymentLookup $lookup,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    public function handle(string $actorUserId, string $tenantId, PaymentFilters $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_VIEW);

        return $this->query($tenantId, $filters)
            ->with(['receivedBy:id,name', 'providerAccount:id,name'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Collected (received), refunded and net, overall and per method.
     *
     * @return array{count: int, collected: int, refunded: int, net: int, currencies: list<string>, by_method: array<string, array{count: int, collected: int, refunded: int, net: int}>}
     */
    public function totals(string $tenantId, PaymentFilters $filters): array
    {
        $rows = $this->query($tenantId, $filters)
            ->whereIn('status', PaymentStatus::collectedValues())
            ->groupBy('method', 'currency')
            ->selectRaw('method, currency, count(*) as total, sum(amount) as collected, sum(refunded_amount) as refunded')
            ->toBase()
            ->get();

        $byMethod = [];
        $currencies = [];

        foreach (PaymentMethod::cases() as $method) {
            $byMethod[$method->value] = ['count' => 0, 'collected' => 0, 'refunded' => 0, 'net' => 0];
        }

        foreach ($rows as $row) {
            $method = (string) $row->method;
            $currencies[(string) $row->currency] = true;
            $byMethod[$method]['count'] += (int) $row->total;
            $byMethod[$method]['collected'] += (int) $row->collected;
            $byMethod[$method]['refunded'] += (int) $row->refunded;
            $byMethod[$method]['net'] = $byMethod[$method]['collected'] - $byMethod[$method]['refunded'];
        }

        $collected = array_sum(array_column($byMethod, 'collected'));
        $refunded = array_sum(array_column($byMethod, 'refunded'));

        return [
            'count' => array_sum(array_column($byMethod, 'count')),
            'collected' => $collected,
            'refunded' => $refunded,
            'net' => $collected - $refunded,
            'currencies' => array_keys($currencies),
            'by_method' => $byMethod,
        ];
    }

    public function show(string $actorUserId, string $tenantId, string $paymentId): Payment
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_VIEW);

        return $this->lookup->find($tenantId, $paymentId)->load(['receivedBy:id,name', 'refunds.refundedBy:id,name', 'reconciliation:id,number', 'providerAccount:id,name,provider', 'requestedBy:id,name']);
    }

    /**
     * @return Builder<Payment>
     */
    private function query(string $tenantId, PaymentFilters $filters): Builder
    {
        $timezone = $this->tenants->timezone($tenantId);

        return Payment::query()
            ->where('tenant_id', $tenantId)
            ->when($filters->lotId !== null, fn (Builder $query) => $query->where('parking_lot_id', $filters->lotId))
            ->when($filters->method !== null, fn (Builder $query) => $query->where('method', $filters->method?->value))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('status', $filters->status?->value))
            ->when($filters->payableType !== null, fn (Builder $query) => $query->where('payable_type', $filters->payableType?->value))
            ->when($filters->payableId !== null, fn (Builder $query) => $query->where('payable_id', $filters->payableId))
            ->when($filters->receivedBy !== null, fn (Builder $query) => $query->where('received_by', $filters->receivedBy))
            ->when($filters->reconciled !== null, fn (Builder $query) => $filters->reconciled ? $query->whereNotNull('reconciliation_id') : $query->whereNull('reconciliation_id'))
            ->when($filters->fromDate !== null, fn (Builder $query) => $query->where('received_at', '>=', CarbonImmutable::parse((string) $filters->fromDate, $timezone)->startOfDay()->utc()))
            ->when($filters->toDate !== null, fn (Builder $query) => $query->where('received_at', '<', CarbonImmutable::parse((string) $filters->toDate, $timezone)->addDay()->startOfDay()->utc()))
            ->when($filters->search !== null && trim((string) $filters->search) !== '', function (Builder $query) use ($filters): void {
                $term = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) $filters->search) ?? '');

                if ($term === '') {
                    return;
                }

                $query->where(fn (Builder $inner) => $inner
                    ->where('number', 'like', "%{$term}%")
                    ->orWhere('plate', 'like', str_replace('-', '', $term).'%'));
            });
    }
}
