<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipVehicle;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\Plate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListMemberships
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * Soonest to end first. The search matches the code or a plate of the
     * membership, without separators.
     *
     * @return LengthAwarePaginator<int, Membership>
     */
    public function handle(string $actorUserId, string $tenantId, ?MembershipStatus $status, ?string $planId, ?string $customerId, ?string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_VIEW);

        $term = $search === null ? '' : Plate::searchTerm($search);

        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->with(['plan:id,name,code,category', 'vehicles'])
            ->when($status !== null, fn ($query) => $query->where('status', $status?->value))
            ->when($planId !== null, fn ($query) => $query->where('plan_id', $planId))
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('code', 'M-'.(str_starts_with($term, 'M') ? substr($term, 1) : $term))
                ->orWhereIn('id', MembershipVehicle::query()->where('tenant_id', $tenantId)->whereNull('removed_at')->where('plate', 'like', '%'.$term.'%')->select('membership_id'))))
            ->orderByRaw("case when status in ('active', 'suspended') then 0 else 1 end")
            ->orderBy('current_period_end')
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    public function show(string $actorUserId, string $tenantId, Membership $membership): Membership
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::MEMBERSHIPS_VIEW);

        return $membership->load(['plan', 'vehicles', 'periods']);
    }
}
