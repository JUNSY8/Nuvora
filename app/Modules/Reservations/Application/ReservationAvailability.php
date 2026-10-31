<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Parking\Domain\SpaceCompatibility;
use App\Modules\Reservations\Domain\ReservationCapacity;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * What a window can still take, with the same rules booking applies: free
 * places in the lot and in each zone, and which reservable spaces are free
 * for the whole window (buffers included). Read only: the answer can change
 * before the booking, which re-checks under the lot's lock.
 */
final class ReservationAvailability
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly ReservationPolicies $policies,
        private readonly ReservationCalendar $calendar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $actorUserId, string $tenantId, string $lotId, VehicleType $vehicleType, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::RESERVATIONS_VIEW);

        $lot = $this->parking->lot($tenantId, $lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

        $policy = $this->policies->for($tenantId, $lot['id']);
        $window = $policy->window($startsAt, $endsAt, now()->toImmutable());
        [$from, $until] = [$window['blocked_from'], $window['blocked_until']];

        $booked = $this->calendar->blocking($tenantId, $lot['id'], $from, $until);
        $pool = array_values(array_filter(
            $this->parking->reservableSpaces($tenantId, $lot['id']),
            fn (array $space): bool => SpaceCompatibility::accepts($space['type'], $vehicleType),
        ));
        $lotAvailable = ReservationCapacity::available(count($pool), ReservationCalendar::intervals($booked), $from, $until);
        $takenSpaces = $booked->pluck('space_id')->filter()->map(fn ($id): string => (string) $id)->all();

        $zones = [];

        foreach ($pool as $space) {
            if ($space['zone_id'] !== null) {
                $zones[$space['zone_id']] = ($zones[$space['zone_id']] ?? 0) + 1;
            }
        }

        $zoneNames = $this->parking->zoneSummaries($tenantId, array_keys($zones));

        return [
            'lot' => ['id' => $lot['id'], 'timezone' => $lot['timezone']],
            'policy' => $policy->toArray(),
            'opens_throughout' => $this->parking->opensThroughout($tenantId, $lot['id'], $lot['timezone'], $startsAt, $endsAt),
            'pool' => count($pool),
            'available' => $lotAvailable,
            'zones' => array_map(function (string $zoneId, int $size) use ($booked, $from, $until, $zoneNames, $lotAvailable): array {
                $inZone = $booked->filter(fn (Reservation $reservation): bool => (string) $reservation->zone_id === $zoneId);

                return [
                    'id' => $zoneId,
                    'name' => $zoneNames[$zoneId]['name'] ?? null,
                    'code' => $zoneNames[$zoneId]['code'] ?? null,
                    'pool' => $size,
                    'available' => min($lotAvailable, ReservationCapacity::available($size, ReservationCalendar::intervals($inZone), $from, $until)),
                ];
            }, array_keys($zones), array_values($zones)),
            'spaces' => array_map(fn (array $space): array => [
                'id' => $space['id'],
                'code' => $space['code'],
                'zone_id' => $space['zone_id'],
                'type' => $space['type']->value,
                'free' => $lotAvailable > 0 && ! in_array($space['id'], $takenSpaces, true),
            ], $pool),
        ];
    }
}
