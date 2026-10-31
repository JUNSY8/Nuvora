<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\DiscountRule;
use App\Modules\Tariffs\Domain\PriceCalculator;
use App\Modules\Tariffs\Domain\TariffDefinition;
use App\Modules\Tariffs\Domain\TariffRuleViolation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Public contract of the Tariffs module for parking sessions and the
 * simulator. It never writes and does not authorize: callers check their
 * own permission before asking for a price.
 *
 * The tariff is the one in force when the vehicle entered, so a price change
 * does not reach stays already inside. A lot that had no applicable tariff
 * at the entry (for example, tariffs configured later) uses the ones in
 * force at the exit.
 */
final class PricingEngine
{
    public function __construct(
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly TariffCatalog $catalog,
        private readonly TariffLookup $lookup,
    ) {}

    public function quote(string $tenantId, PricingRequest $request): Quote
    {
        $lot = $this->parking->lot($tenantId, $request->lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'El parqueadero no existe en esta empresa.']);

        $zoneId = $request->zoneId
            ?? ($request->spaceId === null ? null : $this->parking->spaceZoneId($tenantId, $lot['id'], $request->spaceId));
        $entryLocal = $request->entryAt->setTimezone(new DateTimeZone($lot['timezone']));
        $exitLocal = $request->exitAt->setTimezone(new DateTimeZone($lot['timezone']));

        $manual = $request->discountId === null ? null : $this->manualDiscount($tenantId, $lot['id'], $lot['currency'], $request, $exitLocal);

        $tariff = TariffDefinition::select($this->catalog->inForce($tenantId, $lot['id'], $request->entryAt), $request->vehicleType, $zoneId, $entryLocal)
            ?? TariffDefinition::select($this->catalog->inForce($tenantId, $lot['id'], $request->exitAt), $request->vehicleType, $zoneId, $entryLocal);

        if ($tariff === null) {
            return Quote::unpriced($request->stayMinutes, $lot['currency'], $entryLocal->format(DATE_ATOM), $exitLocal->format(DATE_ATOM), $zoneId);
        }

        $promotions = array_values(array_filter(
            $this->catalog->promotions($tenantId, $lot['id']),
            fn (DiscountRule $rule): bool => $rule->unavailableReason($lot['id'], $lot['currency'], $request->vehicleType, $exitLocal) === null,
        ));

        $result = PriceCalculator::calculate($tariff, $request->stayMinutes, $lot['grace_period_minutes'], $lot['currency'], $manual, $promotions);

        return Quote::priced($tariff, $result, $lot['currency'], $entryLocal->format(DATE_ATOM), $exitLocal->format(DATE_ATOM), $zoneId);
    }

    private function manualDiscount(string $tenantId, string $lotId, string $currency, PricingRequest $request, DateTimeImmutable $exitLocal): DiscountRule
    {
        try {
            $rule = TariffCatalog::discount($this->lookup->discount($tenantId, (string) $request->discountId));
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['discount_id' => 'El descuento no existe en esta empresa.']);
        }

        if ($rule->application !== DiscountApplication::Manual) {
            throw new TariffRuleViolation('Las promociones automáticas se aplican solas; no se eligen en la salida.', 'DISCOUNT_NOT_APPLICABLE');
        }

        $reason = $rule->unavailableReason($lotId, $currency, $request->vehicleType, $exitLocal);

        if ($reason !== null) {
            throw new TariffRuleViolation($reason, 'DISCOUNT_NOT_APPLICABLE');
        }

        return $rule;
    }
}
