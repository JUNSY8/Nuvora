<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\Plate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

final class ListParkingSessions
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
    ) {}

    /**
     * Newest entries first. The plate search ignores separators, so "abc-1"
     * finds ABC123. Dates are whole days in the lot's local time.
     *
     * @return LengthAwarePaginator<int, ParkingSession>
     */
    public function handle(string $actorUserId, string $tenantId, SessionFilters $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_VIEW);

        $query = ParkingSession::query()
            ->where('tenant_id', $tenantId)
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->orderByDesc('entry_at')
            ->orderByDesc('id');

        if ($filters->lotId !== null) {
            $lot = $this->parking->lot($tenantId, $filters->lotId)
                ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

            $query->where('parking_lot_id', $lot['id']);

            if ($filters->fromDate !== null) {
                $query->where('entry_at', '>=', CarbonImmutable::parse($filters->fromDate, $lot['timezone'])->startOfDay()->utc());
            }

            if ($filters->toDate !== null) {
                $query->where('entry_at', '<', CarbonImmutable::parse($filters->toDate, $lot['timezone'])->startOfDay()->addDay()->utc());
            }
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->vehicleId !== null) {
            $query->where('vehicle_id', $filters->vehicleId);
        }

        $search = $filters->search === null ? '' : Plate::searchTerm($filters->search);

        if ($search !== '') {
            $query->where('plate', 'like', '%'.$search.'%');
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }
}
