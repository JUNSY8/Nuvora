<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\CustomerStatus;
use App\Modules\Customers\Domain\CustomerType;
use App\Modules\Customers\Infrastructure\Persistence\Customer;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListCustomers
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * @return array{customers: LengthAwarePaginator<int, Customer>, type_counts: array<string, int>}
     */
    public function handle(
        string $actorUserId,
        string $tenantId,
        int $page,
        int $perPage,
        ?CustomerType $type = null,
        ?CustomerStatus $status = null,
        ?string $businessId = null,
        ?string $search = null,
        bool $organizationsOnly = false,
    ): array {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::CUSTOMERS_VIEW);

        $query = Customer::query()
            ->where('tenant_id', $tenantId)
            ->with('business:id,name')
            ->withCount('members')
            ->orderBy('name')
            ->orderBy('id');

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        if ($organizationsOnly) {
            $query->whereIn('type', [CustomerType::Business->value, CustomerType::Corporate->value]);
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($businessId !== null) {
            $query->where('business_id', $businessId);
        }

        $search = $search === null ? '' : trim($search);

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $document = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($search));
            $query->where(function ($inner) use ($needle, $document): void {
                $inner->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$needle])
                    ->orWhereRaw('coalesce(phone, \'\') like ?', [$needle]);

                if ($document !== '') {
                    $inner->orWhere('document_normalized', 'like', '%'.$document.'%');
                }
            });
        }

        $counts = array_fill_keys(array_map(fn (CustomerType $case): string => $case->value, CustomerType::cases()), 0);

        $rows = Customer::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->type] = (int) $row->aggregate;
        }

        return [
            'customers' => $query->paginate(perPage: $perPage, page: $page),
            'type_counts' => $counts,
        ];
    }
}
