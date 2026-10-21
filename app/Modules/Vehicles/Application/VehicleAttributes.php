<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Application;

use App\Modules\Vehicles\Domain\CountryCode;
use App\Modules\Vehicles\Domain\Plate;
use App\Modules\Vehicles\Infrastructure\Persistence\Vehicle;
use Illuminate\Validation\ValidationException;

final class VehicleAttributes
{
    /**
     * Normalizes the plate and country and rejects a plate already registered
     * in the tenant for that country. The unique index still decides races.
     *
     * @return array<string, mixed>
     */
    public static function resolve(string $tenantId, VehicleData $data, ?string $ignoreVehicleId = null): array
    {
        $plate = Plate::fromInput($data->plate);
        $country = CountryCode::normalize($data->country);

        $taken = Vehicle::query()
            ->where('tenant_id', $tenantId)
            ->where('country', $country)
            ->where('plate', $plate->value)
            ->when($ignoreVehicleId !== null, fn ($query) => $query->whereKeyNot($ignoreVehicleId))
            ->exists();

        if ($taken) {
            throw self::plateTaken();
        }

        return [
            'plate' => $plate->value,
            'country' => $country,
            'type' => $data->type,
            'brand' => self::nullable($data->brand),
            'model' => self::nullable($data->model),
            'color' => self::nullable($data->color),
            'year' => $data->year,
            'is_electric' => $data->isElectric,
            'notes' => self::nullable($data->notes),
        ];
    }

    public static function plateTaken(): ValidationException
    {
        return ValidationException::withMessages(['plate' => 'Ya existe un vehículo con esa placa en la empresa.']);
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
