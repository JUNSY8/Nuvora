<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

use App\Modules\Shared\Domain\Currency;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeInterface;

/**
 * A discount or promotion of the catalog. Validity is checked against the
 * exit in the lot's local time, because that is when the stay is charged.
 * A fixed amount only applies in its own currency.
 */
final class DiscountRule
{
    public const MAX_BASIS_POINTS = 10_000;

    /**
     * @param  list<VehicleType>  $vehicleTypes
     * @param  list<int>  $days  ISO days of the week; empty means every day
     */
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly DiscountType $type,
        public readonly int $value,
        public readonly ?string $currency,
        public readonly DiscountApplication $application,
        public readonly TariffStatus $status,
        public readonly ?string $lotId,
        public readonly array $vehicleTypes,
        public readonly array $days,
        public readonly ?string $validFrom,
        public readonly ?string $validUntil,
    ) {}

    /**
     * Validates the configurable part of a discount before it is saved.
     */
    public static function assertValue(DiscountType $type, int $value, ?string $currency): void
    {
        if ($type === DiscountType::Percentage && ($value < 1 || $value > self::MAX_BASIS_POINTS)) {
            throw new TariffRuleViolation('El porcentaje debe estar entre 0,01 % y 100 %.', 'INVALID_DISCOUNT');
        }

        if ($type === DiscountType::FreeMinutes && ($value < 1 || $value > 31 * RateSchedule::DAY_MINUTES)) {
            throw new TariffRuleViolation('Los minutos gratis deben estar entre 1 y 31 días.', 'INVALID_DISCOUNT');
        }

        if ($type !== DiscountType::FixedAmount) {
            return;
        }

        if ($currency === null) {
            throw new TariffRuleViolation('Un descuento de valor fijo necesita moneda.', 'INVALID_DISCOUNT');
        }

        if ($value < 1 || ! Currency::isChargeable($value, $currency)) {
            throw new TariffRuleViolation("El valor del descuento no se puede cobrar en {$currency}: usa unidades enteras de la moneda.", 'INVALID_DISCOUNT');
        }
    }

    /**
     * Why the discount cannot be used for this stay, or null when it can.
     *
     * @param  DateTimeInterface  $exitLocal  the exit in the lot's timezone
     */
    public function unavailableReason(string $lotId, string $currency, VehicleType $vehicleType, DateTimeInterface $exitLocal): ?string
    {
        $date = $exitLocal->format('Y-m-d');

        return match (true) {
            $this->status !== TariffStatus::Active => 'El descuento está inactivo.',
            $this->lotId !== null && $this->lotId !== $lotId => 'El descuento no aplica en este parqueadero.',
            $this->type === DiscountType::FixedAmount && $this->currency !== $currency => "El descuento está en {$this->currency} y el parqueadero cobra en {$currency}.",
            $this->vehicleTypes !== [] && ! in_array($vehicleType, $this->vehicleTypes, true) => 'El descuento no aplica a este tipo de vehículo.',
            $this->validFrom !== null && $date < $this->validFrom => 'El descuento todavía no está vigente.',
            $this->validUntil !== null && $date > $this->validUntil => 'El descuento ya venció.',
            $this->days !== [] && ! in_array((int) $exitLocal->format('N'), $this->days, true) => 'El descuento no aplica este día de la semana.',
            default => null,
        };
    }

    /**
     * Amount taken off the subtotal. Free minutes are priced by the caller,
     * which knows the tariff; percentages round half up to the currency step.
     *
     * @param  int  $reducedPrice  price of the stay without the free minutes
     */
    public function reduction(int $subtotal, int $reducedPrice, string $currency): int
    {
        $step = Currency::step($currency);

        $reduction = match ($this->type) {
            // One rounding, half up, straight to the currency step.
            DiscountType::Percentage => intdiv($subtotal * $this->value + $step * 5_000, $step * 10_000) * $step,
            DiscountType::FixedAmount => $this->value,
            DiscountType::FreeMinutes => $subtotal - $reducedPrice,
        };

        return max(0, min($subtotal, $reduction));
    }

    public function label(): string
    {
        return match ($this->type) {
            DiscountType::Percentage => "{$this->name} (".self::percent($this->value).')',
            DiscountType::FixedAmount => $this->name,
            DiscountType::FreeMinutes => "{$this->name} ({$this->value} min gratis)",
        };
    }

    private static function percent(int $basisPoints): string
    {
        $value = rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',');

        return "{$value} %";
    }
}
