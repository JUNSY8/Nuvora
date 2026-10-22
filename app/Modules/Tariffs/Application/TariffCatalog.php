<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\DiscountRule;
use App\Modules\Tariffs\Domain\RateSchedule;
use App\Modules\Tariffs\Domain\TariffDefinition;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Domain\TimeWindow;
use App\Modules\Tariffs\Infrastructure\Persistence\Discount;
use App\Modules\Tariffs\Infrastructure\Persistence\TariffVersion;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeInterface;

/**
 * Turns stored tariffs and discounts into domain rules. The tariffs in
 * force at a moment are, for each tariff of the lot, its latest version
 * published at or before that moment.
 */
final class TariffCatalog
{
    /**
     * @return list<TariffDefinition>
     */
    public function inForce(string $tenantId, string $lotId, DateTimeInterface $at): array
    {
        return TariffVersion::query()
            ->where('tenant_id', $tenantId)
            ->where('parking_lot_id', $lotId)
            ->where('effective_from', '<=', $at)
            ->orderBy('tariff_id')
            ->orderByDesc('version')
            ->get()
            ->unique('tariff_id')
            ->map(fn (TariffVersion $version): TariffDefinition => self::definition($version))
            ->values()
            ->all();
    }

    /**
     * Active automatic promotions of the lot (or of every lot), in code order.
     *
     * @return list<DiscountRule>
     */
    public function promotions(string $tenantId, string $lotId): array
    {
        return Discount::query()
            ->where('tenant_id', $tenantId)
            ->where('application', DiscountApplication::Automatic->value)
            ->where('status', TariffStatus::Active->value)
            ->where(fn ($query) => $query->whereNull('parking_lot_id')->orWhere('parking_lot_id', $lotId))
            ->orderBy('code')
            ->get()
            ->map(fn (Discount $discount): DiscountRule => self::discount($discount))
            ->all();
    }

    public static function definition(TariffVersion $version): TariffDefinition
    {
        return new TariffDefinition(
            tariffId: (string) $version->tariff_id,
            versionId: (string) $version->id,
            version: $version->version,
            code: $version->code,
            name: $version->name,
            status: $version->status,
            priority: $version->priority,
            vehicleTypes: self::vehicleTypes($version->vehicle_types),
            zoneId: $version->zone_id === null ? null : (string) $version->zone_id,
            windows: array_map(fn (array $window): TimeWindow => TimeWindow::fromArray($window), $version->windows),
            graceMinutes: $version->grace_minutes,
            rates: RateSchedule::restore($version->brackets, $version->step_minutes, $version->step_amount, $version->daily_max_amount),
        );
    }

    public static function discount(Discount $discount): DiscountRule
    {
        return new DiscountRule(
            id: (string) $discount->id,
            code: $discount->code,
            name: $discount->name,
            type: $discount->type,
            value: $discount->value,
            currency: $discount->currency,
            application: $discount->application,
            status: $discount->status,
            lotId: $discount->parking_lot_id === null ? null : (string) $discount->parking_lot_id,
            vehicleTypes: self::vehicleTypes($discount->vehicle_types),
            days: array_values(array_map('intval', $discount->days)),
            validFrom: $discount->valid_from === null ? null : substr((string) $discount->valid_from, 0, 10),
            validUntil: $discount->valid_until === null ? null : substr((string) $discount->valid_until, 0, 10),
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return list<VehicleType>
     */
    private static function vehicleTypes(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?VehicleType => is_string($value) ? VehicleType::tryFrom($value) : null,
            $values,
        )));
    }
}
