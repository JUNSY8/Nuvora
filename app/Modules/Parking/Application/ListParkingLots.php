<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\Occupancy;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListParkingLots
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingOccupancyReader $occupancy,
    ) {}

    /**
     * @return array{lots: LengthAwarePaginator<int, ParkingLot>, occupancy: array<string, Occupancy>}
     */
    public function handle(string $actorUserId, string $tenantId, int $page, int $perPage, ?string $search = null): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PARKING_VIEW);

        $query = ParkingLot::query()
            ->where('tenant_id', $tenantId)
            ->withCount(['zones', 'gates'])
            ->orderBy('name')
            ->orderBy('id');

        $search = $search === null ? '' : trim($search);

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->whereRaw('lower(name) like ?', [$needle])
                    ->orWhereRaw('lower(code) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(city, \'\')) like ?', [$needle]);
            });
        }

        $lots = $query->paginate(perPage: $perPage, page: $page);

        $ids = collect($lots->items())->map(fn (ParkingLot $lot): string => (string) $lot->id)->values()->all();

        return [
            'lots' => $lots,
            'occupancy' => $this->occupancy->forLots($tenantId, $ids),
        ];
    }
}
