<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Reservations\Domain\ReservationCode;
use App\Modules\Reservations\Domain\ReservationStatus;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\Plate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

final class ListReservations
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
    ) {}

    /**
     * Soonest first. The search matches plates without separators and codes
     * ("r7k3" finds R-7K3M9Q). Dates are local days of the lot by start.
     *
     * @param  list<ReservationStatus>  $statuses
     * @return LengthAwarePaginator<int, Reservation>
     */
    public function handle(string $actorUserId, string $tenantId, ?string $lotId, array $statuses, ?string $search, ?string $fromDate, ?string $toDate, ?string $vehicleId, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::RESERVATIONS_VIEW);

        $query = Reservation::query()
            ->where('tenant_id', $tenantId)
            ->with('createdBy:id,name')
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($lotId !== null) {
            $lot = $this->parking->lot($tenantId, $lotId)
                ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

            $query->where('parking_lot_id', $lot['id']);

            if ($fromDate !== null) {
                $query->where('starts_at', '>=', CarbonImmutable::parse($fromDate, $lot['timezone'])->startOfDay()->utc());
            }

            if ($toDate !== null) {
                $query->where('starts_at', '<', CarbonImmutable::parse($toDate, $lot['timezone'])->startOfDay()->addDay()->utc());
            }
        }

        if ($statuses !== []) {
            $query->whereIn('status', array_map(fn (ReservationStatus $status): string => $status->value, $statuses));
        }

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }

        $term = $search === null ? '' : Plate::searchTerm($search);

        if ($term !== '') {
            $code = ReservationCode::normalize($search ?? '');
            $query->where(fn ($inner) => $inner->where('plate', 'like', '%'.$term.'%')->orWhere('code', $code));
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }
}
