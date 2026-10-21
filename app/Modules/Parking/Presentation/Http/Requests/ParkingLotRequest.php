<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Requests;

use App\Modules\Parking\Application\ParkingLotData;
use App\Modules\Parking\Domain\ParkingLotStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ParkingLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'timezone' => ['required', 'timezone'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'grace_period_minutes' => ['required', 'integer', 'between:0,240'],
            'status' => ['required', Rule::enum(ParkingLotStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del parqueadero es obligatorio.',
            'code.required' => 'El código del parqueadero es obligatorio.',
            'timezone.timezone' => 'La zona horaria no es válida.',
            'currency.regex' => 'La moneda debe ser un código ISO de tres letras, por ejemplo COP.',
            'grace_period_minutes.between' => 'El tiempo de gracia debe estar entre 0 y 240 minutos.',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
            'latitude.required_with' => 'Indica latitud y longitud juntas.',
            'longitude.required_with' => 'Indica latitud y longitud juntas.',
        ];
    }

    public function toData(): ParkingLotData
    {
        $latitude = $this->validated('latitude');
        $longitude = $this->validated('longitude');

        return new ParkingLotData(
            name: (string) $this->validated('name'),
            code: (string) $this->validated('code'),
            description: $this->validated('description'),
            address: $this->validated('address'),
            city: $this->validated('city'),
            latitude: $latitude === null ? null : (string) $latitude,
            longitude: $longitude === null ? null : (string) $longitude,
            timezone: (string) $this->validated('timezone'),
            currency: (string) $this->validated('currency'),
            gracePeriodMinutes: (int) $this->validated('grace_period_minutes'),
            status: ParkingLotStatus::from((string) $this->validated('status')),
        );
    }
}
