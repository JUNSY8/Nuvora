<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\Memberships\Application\MembershipDirectory;
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
 *
 * A stay that entered under a membership is covered: it is still priced
 * with the tariff, to record what the membership saved, but its total is
 * zero and discounts do not apply.
 */
final class SessionPricing
{
    public function __construct(
        private readonly PricingEngine $engine,
        private readonly MembershipDirectory $memberships,
    ) {}

    /**
     * The charge as the API shows it (a quote, or the explanation stored
     * with a closed session).
     *
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, ParkingSession $session, DateTimeImmutable $exitAt, ?string $discountId): array
    {
        [$quote, $membership] = $this->price($tenantId, $session, $exitAt, $discountId);

        return $membership === null ? $quote->toArray() : self::covered($quote, $membership);
    }

    /**
     * Columns that record the charge on the closed session.
     *
     * @return array<string, mixed>
     */
    public function charge(string $tenantId, ParkingSession $session, DateTimeImmutable $exitAt, ?string $discountId): array
    {
        [$quote, $membership] = $this->price($tenantId, $session, $exitAt, $discountId);

        if ($membership !== null) {
            $pricing = self::covered($quote, $membership);

            return [
                'charge_status' => ChargeStatus::Covered,
                'currency' => $quote->currency,
                'tariff_id' => $quote->tariff['id'] ?? null,
                'tariff_version_id' => $quote->tariff['version_id'] ?? null,
                'discount_id' => null,
                'charged_minutes' => $quote->stayMinutes,
                'subtotal_amount' => $pricing['subtotal'],
                'discount_amount' => $pricing['discount'],
                'total_amount' => 0,
                'pricing' => $pricing,
            ];
        }

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

    /**
     * @return array{0: Quote, 1: array{id: string, code: string, plan: string}|null}
     */
    private function price(string $tenantId, ParkingSession $session, DateTimeImmutable $exitAt, ?string $discountId): array
    {
        $membership = $session->membership_id === null
            ? null
            : ($this->memberships->summaries($tenantId, [(string) $session->membership_id])[(string) $session->membership_id] ?? null);

        $quote = $this->engine->quote($tenantId, new PricingRequest(
            lotId: (string) $session->parking_lot_id,
            vehicleType: VehicleType::from($session->vehicle_type),
            entryAt: $session->entry_at,
            exitAt: $exitAt,
            stayMinutes: StayDuration::minutes($session->entry_at, $exitAt),
            spaceId: $session->space_id === null ? null : (string) $session->space_id,
            // A covered stay takes no discount; the membership pays it.
            discountId: $membership === null ? $discountId : null,
        ));

        return [$quote, $membership];
    }

    /**
     * The tariff's price before discounts, fully discounted by the membership.
     *
     * @param  array{id: string, code: string, plan: string}  $membership
     * @return array<string, mixed>
     */
    private static function covered(Quote $quote, array $membership): array
    {
        $value = $quote->subtotal ?? 0;
        $lines = array_values(array_filter($quote->lines, fn (array $line): bool => $line['type'] !== 'discount'));
        $lines[] = ['type' => 'membership', 'label' => "Mensualidad {$membership['code']} ({$membership['plan']})", 'amount' => -$value, 'quantity' => null, 'unit_amount' => null];

        return [
            ...$quote->toArray(),
            'status' => 'covered',
            'discount_rule' => null,
            'subtotal' => $value,
            'discount' => $value,
            'total' => 0,
            'lines' => $lines,
            'membership' => $membership,
            'reason' => null,
        ];
    }
}
