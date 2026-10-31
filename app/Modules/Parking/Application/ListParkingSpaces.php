<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListParkingSpaces
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingLookup $lookup,
    ) {}

    /**
     * Filters: zone_id (or "none" for spaces without zone), status, type and a
     * code search. Spaces are ordered by code.
     *
     * @return LengthAwarePaginator<int, ParkingSpace>
     */
    public function handle(
        string $actorUserId,
        string $tenantId,
        string $lotId,
        int $page,
        int $perPage,
        ?string $zoneId = null,
        ?SpaceStatus $status = null,
        ?SpaceType $type = null,
        ?string $search = null,
    ): LengthAwarePaginator {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_VIEW);

        $lot = $this->lookup->lot($tenantId, $lotId);

        $query = ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lot->id)
            ->with('zone:id,name,code')
            ->orderBy('code')
            ->orderBy('id');

        if ($zoneId === 'none') {
            $query->whereNull('zone_id');
        } elseif ($zoneId !== null) {
            $query->where('zone_id', $zoneId);
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        $search = $search === null ? '' : mb_strtoupper(trim($search));

        if ($search !== '') {
            $query->where('code', 'like', '%'.$search.'%');
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }
}
