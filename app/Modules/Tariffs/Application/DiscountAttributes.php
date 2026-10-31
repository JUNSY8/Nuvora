<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Tariffs\Domain\DiscountRule;
use App\Modules\Tariffs\Domain\DiscountType;
use App\Modules\Tariffs\Domain\TariffCode;
use App\Modules\Tariffs\Domain\TariffRuleViolation;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Validation\ValidationException;

/**
 * Validated columns of a discount. A fixed amount takes the currency of its
 * lot; a discount for every lot must name its currency.
 */
final class DiscountAttributes
{
    public function __construct(private readonly ParkingInfrastructureDirectory $parking) {}

    /**
     * @return array<string, mixed>
     */
    public function from(string $tenantId, DiscountData $data): array
    {
        $currency = null;

        if ($data->lotId !== null) {
            $lot = $this->parking->lot($tenantId, $data->lotId)
                ?? throw ValidationException::withMessages(['parking_lot_id' => 'Selecciona un parqueadero de esta empresa.']);
            $currency = $lot['currency'];
        }

        if ($data->type === DiscountType::FixedAmount) {
            $currency ??= $data->currency === null ? null : strtoupper($data->currency);
        } else {
            $currency = null;
        }

        DiscountRule::assertValue($data->type, $data->value, $currency);

        $days = array_values(array_unique($data->days));
        sort($days);

        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                throw new TariffRuleViolation('Los días van de 1 (lunes) a 7 (domingo).', 'INVALID_DISCOUNT');
            }
        }

        if ($data->validFrom !== null && $data->validUntil !== null && $data->validFrom > $data->validUntil) {
            throw ValidationException::withMessages(['valid_until' => 'La vigencia debe terminar después de empezar.']);
        }

        return [
            'parking_lot_id' => $data->lotId,
            'code' => TariffCode::normalize($data->code),
            'name' => trim($data->name),
            'type' => $data->type,
            'value' => $data->value,
            'currency' => $currency,
            'application' => $data->application,
            'vehicle_types' => array_values(array_map(
                fn (VehicleType $type): string => $type->value,
                array_filter(VehicleType::cases(), fn (VehicleType $type): bool => in_array($type, $data->vehicleTypes, true)),
            )),
            'days' => $days,
            'valid_from' => $data->validFrom,
            'valid_until' => $data->validUntil,
        ];
    }

    public static function codeTaken(): ValidationException
    {
        return ValidationException::withMessages(['code' => 'Ya existe un descuento con ese código en la empresa.']);
    }
}
