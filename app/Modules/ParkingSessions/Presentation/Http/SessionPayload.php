<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Presentation\Http;

use App\Modules\Parking\Domain\Occupancy;
use App\Modules\ParkingSessions\Application\SessionReferences;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Payments\Domain\PaymentBalance;

final class SessionPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function session(ParkingSession $session, SessionReferences $refs): array
    {
        $customer = $session->customer_id === null ? null : ($refs->customers[(string) $session->customer_id] ?? null);

        return [
            'id' => $session->id,
            'status' => $session->status->value,
            'parking_lot' => $refs->lots[(string) $session->parking_lot_id] ?? ['id' => $session->parking_lot_id, 'name' => null, 'code' => null],
            'vehicle' => [
                'id' => $session->vehicle_id,
                'plate' => $session->plate,
                'country' => $session->country,
                'type' => $session->vehicle_type,
            ],
            'customer' => $customer === null ? null : ['id' => $customer['id'], 'name' => $customer['name'], 'type' => $customer['type']],
            'space' => $session->space_id === null ? null : ['id' => $session->space_id, 'code' => $session->space_code],
            'entry_at' => $session->entry_at->toIso8601String(),
            'exit_at' => $session->exit_at?->toIso8601String(),
            'cancelled_at' => $session->cancelled_at?->toIso8601String(),
            'duration_minutes' => $session->duration_minutes,
            'entry_source' => $session->entry_source->value,
            'exit_source' => $session->exit_source?->value,
            'entry_gate' => self::gate($refs, $session->entry_gate_id),
            'exit_gate' => self::gate($refs, $session->exit_gate_id),
            'opened_by' => $session->openedBy === null ? null : ['id' => $session->openedBy->id, 'name' => $session->openedBy->name],
            'closed_by' => $session->closedBy === null ? null : ['id' => $session->closedBy->id, 'name' => $session->closedBy->name],
            'cancel_reason' => $session->cancel_reason,
            'notes' => $session->notes,
            'charge' => self::charge($session),
            'payment' => self::payment($session, $refs),
            'reservation' => $session->reservation_id === null ? null : ($refs->reservations[(string) $session->reservation_id] ?? null),
            'membership' => $session->membership_id === null ? null : ($refs->memberships[(string) $session->membership_id] ?? null),
        ];
    }

    /**
     * The charge stored at the exit, with the explanation of how it was
     * computed. Null while the session is open or when it was cancelled.
     *
     * @return array<string, mixed>|null
     */
    private static function charge(ParkingSession $session): ?array
    {
        if ($session->charge_status === null) {
            return null;
        }

        $pricing = $session->pricing ?? [];

        return [
            'status' => $session->charge_status->value,
            'currency' => $session->currency,
            'subtotal' => $session->subtotal_amount,
            'discount' => $session->discount_amount,
            'total' => $session->total_amount,
            'charged_minutes' => $session->charged_minutes,
            'grace_applied' => (bool) ($pricing['grace_applied'] ?? false),
            'tariff' => $pricing['tariff'] ?? null,
            'discount_rule' => $pricing['discount_rule'] ?? null,
            'membership' => $pricing['membership'] ?? null,
            'lines' => $pricing['lines'] ?? [],
            'reason' => $pricing['reason'] ?? null,
        ];
    }

    /**
     * What the stay owes and what was collected. Only closed, priced stays
     * charge money; the rest report "not_required".
     *
     * @return array{status: string, due: int, paid: int, balance: int}|null
     */
    private static function payment(ParkingSession $session, SessionReferences $refs): ?array
    {
        if ($session->charge_status === null) {
            return null;
        }

        return ($refs->payments[(string) $session->id] ?? new PaymentBalance(0, 0))->toArray();
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function summary(array $summary): array
    {
        /** @var Occupancy $occupancy */
        $occupancy = $summary['occupancy'];

        return [...$summary, 'occupancy' => [
            'total' => $occupancy->total,
            'capacity' => $occupancy->capacity,
            'available' => $occupancy->available,
            'in_use' => $occupancy->inUse,
            'rate' => $occupancy->rate(),
            'by_status' => $occupancy->byStatus,
        ]];
    }

    /**
     * @return array{id: string, name: string, code: string}|null
     */
    private static function gate(SessionReferences $refs, ?string $gateId): ?array
    {
        return $gateId === null ? null : ($refs->gates[$gateId] ?? null);
    }
}
