<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Presentation\Http\Requests;

use App\Modules\Tariffs\Application\DiscountData;
use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\DiscountType;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Percentages are basis points (2000 = 20 %), fixed amounts minor units and
 * free minutes whole minutes.
 */
final class DiscountRequest extends FormRequest
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
            'parking_lot_id' => ['nullable', 'ulid'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'application' => ['required', Rule::enum(DiscountApplication::class)],
            'vehicle_types' => ['present', 'array'],
            'vehicle_types.*' => ['distinct', Rule::enum(VehicleType::class)],
            'days' => ['present', 'array', 'max:7'],
            'days.*' => ['integer', 'between:1,7'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código del descuento es obligatorio.',
            'name.required' => 'El nombre del descuento es obligatorio.',
            'type.required' => 'Selecciona el tipo de descuento.',
            'value.required' => 'Indica el valor del descuento.',
            'value.min' => 'El valor del descuento debe ser mayor que cero.',
            'application.required' => 'Indica si el descuento es manual o automático.',
            'currency.regex' => 'La moneda debe ser un código ISO de tres letras, por ejemplo COP.',
        ];
    }

    public function toData(): DiscountData
    {
        $lotId = $this->validated('parking_lot_id');
        $currency = $this->validated('currency');

        return new DiscountData(
            lotId: is_string($lotId) ? strtolower($lotId) : null,
            code: (string) $this->validated('code'),
            name: (string) $this->validated('name'),
            type: DiscountType::from((string) $this->validated('type')),
            value: (int) $this->validated('value'),
            currency: is_string($currency) ? strtoupper($currency) : null,
            application: DiscountApplication::from((string) $this->validated('application')),
            vehicleTypes: array_map(fn (mixed $type): VehicleType => VehicleType::from((string) $type), (array) $this->validated('vehicle_types')),
            days: array_map('intval', (array) $this->validated('days')),
            validFrom: $this->validated('valid_from'),
            validUntil: $this->validated('valid_until'),
        );
    }
}
