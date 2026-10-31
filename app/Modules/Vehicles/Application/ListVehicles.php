<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Domain\VehicleStatus;
use App\Modules\Vehicles\Domain\VehicleType;
use App\Modules\Vehicles\Infrastructure\Persistence\CustomerVehicle;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListVehicles
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * @return array{vehicles: LengthAwarePaginator<int, Vehicle>, status_counts: array<string, int>}
     */
    public function handle(
        string $actorUserId,
        string $tenantId,
        int $page,
        int $perPage,
        ?VehicleStatus $status = null,
        ?VehicleType $type = null,
        ?string $customerId = null,
        ?string $search = null,
    ): array {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::VEHICLES_VIEW);

        $query = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->with('activeLinks')
            ->orderBy('plate')
            ->orderBy('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        if ($customerId !== null) {
            $query->whereIn('id', CustomerVehicle::query()
                ->select('vehicle_id')
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->whereNull('ended_at'));
        }

        $search = $search === null ? '' : trim($search);

        if ($search !== '') {
            $plate = Plate::searchTerm($search);
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($inner) use ($plate, $needle): void {
                if ($plate !== '') {
                    $inner->where('plate', 'like', '%'.$plate.'%');
                }

                $inner->orWhereRaw('lower(coalesce(brand, \'\')) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(model, \'\')) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(color, \'\')) like ?', [$needle]);
            });
        }

        $counts = array_fill_keys(array_map(fn (VehicleStatus $case): string => $case->value, VehicleStatus::cases()), 0);

        $rows = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate;
        }

        return [
            'vehicles' => $query->paginate(perPage: $perPage, page: $page),
            'status_counts' => $counts,
        ];
    }
}
