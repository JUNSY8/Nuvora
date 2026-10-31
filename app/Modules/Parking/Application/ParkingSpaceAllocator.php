<?php

declare(strict_types=1);

namespace App\Modules\Parking\Application;

use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\SpaceType;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Public contract of the Parking module for parking sessions: it moves a
 * space between available and occupied through the space state machine.
 * Callers must run it inside their own transaction, so the space and the
 * session that holds it commit or fail together.
 */
final class ParkingSpaceAllocator
{
    /**
     * Occupies a space chosen by the operator. A space held for a reservation
     * is only taken by that reservation's arrival ($allowHeld).
     *
     * @return array{id: string, code: string, type: SpaceType}
     */
    public function occupy(string $tenantId, string $lotId, string $spaceId, bool $allowHeld = false): array
    {
        try {
            $space = ParkingSpace::query()
                ->where('tenant_id', $tenantId)
                ->where('parking_lot_id', $lotId)
                ->whereKey($spaceId)
                ->lockForUpdate()
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new ParkingRuleViolation('El espacio no pertenece a este parqueadero.', 'SPACE_NOT_FOUND');
        }

        $usable = $space->status === SpaceStatus::Available || ($allowHeld && $space->status === SpaceStatus::Reserved);

        if (! $usable) {
            throw new ParkingRuleViolation("El espacio {$space->code} no está disponible.", 'SPACE_NOT_AVAILABLE');
        }

        return $this->mark($space, SpaceStatus::Occupied);
    }

    /**
     * Occupies the first available space of the first type that has one, in
     * code order. On PostgreSQL rows locked by a concurrent entry are skipped
     * instead of waited for, so two operators get two different spaces.
     *
     * Reservable spaces form the reservation pool: walk-ins use them last,
     * arrivals with a reservation use them first. Spaces assignable to
     * memberships come after the others. A zone narrows the search.
     *
     * @param  list<SpaceType>  $types  in order of preference
     * @return array{id: string, code: string, type: SpaceType}|null
     */
    public function occupyFirstAvailable(string $tenantId, string $lotId, array $types, ?string $zoneId = null, bool $reservableFirst = false): ?array
    {
        foreach ($types as $type) {
            $query = ParkingSpace::query()
                ->where('tenant_id', $tenantId)
                ->where('parking_lot_id', $lotId)
                ->where('type', $type->value)
                ->where('status', SpaceStatus::Available->value)
                ->when($zoneId !== null, fn ($query) => $query->where('zone_id', $zoneId))
                ->orderBy('is_reservable', $reservableFirst ? 'desc' : 'asc')
                ->orderBy('is_assignable')
                ->orderBy('code')
                ->orderBy('id')
                ->limit(1);

            $query = DB::getDriverName() === 'pgsql'
                ? $query->lock('for update skip locked')
                : $query->lockForUpdate();

            $space = $query->first();

            if ($space instanceof ParkingSpace) {
                return $this->mark($space, SpaceStatus::Occupied);
            }
        }

        return null;
    }

    /**
     * Frees the space held by a session that ended. A space that is no longer
     * occupied is left as it is: the session still ends.
     */
    public function release(string $tenantId, string $lotId, string $spaceId): void
    {
        $space = ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($spaceId)
            ->lockForUpdate()
            ->first();

        if ($space instanceof ParkingSpace && $space->status === SpaceStatus::Occupied) {
            $this->mark($space, SpaceStatus::Available);
        }
    }

    /**
     * Holds a space for a reservation whose window started. Only an available
     * space can be held; an occupied one stays occupied and the arrival is
     * placed elsewhere.
     */
    public function hold(string $tenantId, string $lotId, string $spaceId): bool
    {
        $space = ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($spaceId)
            ->lockForUpdate()
            ->first();

        if (! $space instanceof ParkingSpace || $space->status !== SpaceStatus::Available) {
            return false;
        }

        $this->mark($space, SpaceStatus::Reserved);

        return true;
    }

    /**
     * Gives back a held space when its reservation ends without an arrival.
     */
    public function releaseHold(string $tenantId, string $lotId, string $spaceId): void
    {
        $space = ParkingSpace::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->whereKey($spaceId)
            ->lockForUpdate()
            ->first();

        if ($space instanceof ParkingSpace && $space->status === SpaceStatus::Reserved) {
            $this->mark($space, SpaceStatus::Available);
        }
    }

    /**
     * @return array{id: string, code: string, type: SpaceType}
     */
    private function mark(ParkingSpace $space, SpaceStatus $status): array
    {
        $space->fill(['status' => $status, 'status_changed_at' => now()])->save();

        return ['id' => (string) $space->id, 'code' => $space->code, 'type' => $space->type];
    }
}
