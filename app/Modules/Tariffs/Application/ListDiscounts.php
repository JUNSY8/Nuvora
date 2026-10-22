<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Infrastructure\Persistence\Discount;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\Eloquent\Collection;

final class ListDiscounts
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * A lot filter returns that lot's discounts and those for every lot.
     *
     * @return Collection<int, Discount>
     */
    public function handle(string $actorUserId, string $tenantId, ?string $lotId, ?DiscountApplication $application, ?TariffStatus $status): Collection
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_VIEW);

        return Discount::query()
            ->where('tenant_id', $tenantId)
            ->when($lotId !== null, fn ($query) => $query->where(
                fn ($inner) => $inner->whereNull('parking_lot_id')->orWhere('parking_lot_id', $lotId),
            ))
            ->when($application !== null, fn ($query) => $query->where('application', $application?->value))
            ->when($status !== null, fn ($query) => $query->where('status', $status?->value))
            ->orderBy('status')
            ->orderBy('code')
            ->limit(200)
            ->get();
    }
}
