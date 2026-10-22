<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\ParkingSessions\Domain\ChargeStatus;
use App\Modules\ParkingSessions\Domain\StayDuration;
use App\Modules\ParkingSessions\Infrastructure\Persistence\ParkingSession;
use App\Modules\Tariffs\Application\PricingEngine;
use App\Modules\Tariffs\Application\PricingRequest;
use App\Modules\Tariffs\Application\Quote;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;

/**
 * Asks the Tariffs module for the price of a stay. The vehicle type is the
 * one copied at the entry and the zone is the current zone of the space.
 */
final class SessionPricing
{
    public function __construct(private readonly PricingEngine $engine) {}

    public function quote(string $tenantId, ParkingSession $session, DateTimeImmutable $exitAt, ?string $discountId): Quote
    {
        return $this->engine->quote($tenantId, new PricingRequest(
            lotId: (string) $session->parking_lot_id,
            vehicleType: VehicleType::from($session->vehicle_type),
            entryAt: $session->entry_at,
            exitAt: $exitAt,
            stayMinutes: StayDuration::minutes($session->entry_at, $exitAt),
            spaceId: $session->space_id === null ? null : (string) $session->space_id,
            discountId: $discountId,
        ));
    }

    /**
     * Columns that record the charge on the closed session.
     *
     * @return array<string, mixed>
     */
    public static function chargeAttributes(Quote $quote): array
    {
        return [
            'charge_status' => $quote->priced ? ChargeStatus::Priced : ChargeStatus::Unpriced,
            'currency' => $quote->priced ? $quote->currency : null,
            'tariff_id' => $quote->tariff['id'] ?? null,
            'tariff_version_id' => $quote->tariff['version_id'] ?? null,
            'discount_id' => $quote->discountRule['id'] ?? null,
            'charged_minutes' => $quote->chargedMinutes,
            'subtotal_amount' => $quote->subtotal,
            'discount_amount' => $quote->discount,
            'total_amount' => $quote->total,
            'pricing' => $quote->toArray(),
        ];
    }
}
