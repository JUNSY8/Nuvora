<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Presentation\Http;

use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Reservations\Infrastructure\Persistence\Reservation;

final class ReservationPayload
{
    /**
     * @param  iterable<Reservation>  $reservations
     * @return list<array<string, mixed>>
     */
    public static function many(string $tenantId, iterable $reservations): array
    {
        $lots = [];
        $zones = [];
        $customers = [];

        foreach ($reservations as $reservation) {
            $lots[(string) $reservation->parking_lot_id] = true;

            if ($reservation->zone_id !== null) {
                $zones[(string) $reservation->zone_id] = true;
            }

            if ($reservation->customer_id !== null) {
                $customers[(string) $reservation->customer_id] = true;
            }
        }

        $parking = app(ParkingInfrastructureDirectory::class);
        $lotNames = $parking->lotSummaries($tenantId, array_keys($lots));
        $zoneNames = $parking->zoneSummaries($tenantId, array_keys($zones));
        $customerNames = app(CustomerDirectory::class)->summaries($tenantId, array_keys($customers));
        $items = [];

        foreach ($reservations as $reservation) {
            $customer = $reservation->customer_id === null ? null : ($customerNames[(string) $reservation->customer_id] ?? null);
            $zoneId = $reservation->zone_id === null ? null : (string) $reservation->zone_id;

            $items[] = [
                'id' => $reservation->id,
                'code' => $reservation->code,
                'status' => $reservation->status->value,
                'parking_lot' => $lotNames[(string) $reservation->parking_lot_id] ?? ['id' => $reservation->parking_lot_id, 'name' => null, 'code' => null],
                'vehicle' => ['id' => $reservation->vehicle_id, 'plate' => $reservation->plate, 'country' => $reservation->country, 'type' => $reservation->vehicle_type],
                'customer' => $customer === null ? null : ['id' => $customer['id'], 'name' => $customer['name'], 'type' => $customer['type']],
                'scope' => $reservation->space_id !== null ? 'space' : ($zoneId !== null ? 'zone' : 'lot'),
                'zone' => $zoneId === null ? null : ($zoneNames[$zoneId] ?? ['id' => $zoneId, 'name' => null, 'code' => null]),
                'space' => $reservation->space_id === null ? null : ['id' => $reservation->space_id, 'code' => $reservation->space_code],
                'starts_at' => $reservation->starts_at->toIso8601String(),
                'ends_at' => $reservation->ends_at->toIso8601String(),
                'arrival_from' => $reservation->arrival_from->toIso8601String(),
                'no_show_after' => $reservation->no_show_after->toIso8601String(),
                'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
                'confirmed_at' => $reservation->confirmed_at?->toIso8601String(),
                'checked_in_at' => $reservation->checked_in_at?->toIso8601String(),
                'completed_at' => $reservation->completed_at?->toIso8601String(),
                'cancelled_at' => $reservation->cancelled_at?->toIso8601String(),
                'expired_at' => $reservation->expired_at?->toIso8601String(),
                'cancel_reason' => $reservation->cancel_reason,
                'currency' => $reservation->currency,
                'quoted_amount' => $reservation->quoted_amount,
                'pricing' => $reservation->pricing,
                'notes' => $reservation->notes,
                'created_by' => $reservation->createdBy === null ? null : ['id' => $reservation->createdBy->id, 'name' => $reservation->createdBy->name],
                'created_at' => $reservation->created_at?->toIso8601String(),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(string $tenantId, Reservation $reservation): array
    {
        return self::many($tenantId, [$reservation->loadMissing('createdBy:id,name')])[0];
    }
}
