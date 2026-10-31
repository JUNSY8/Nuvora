<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Presentation\Http\Requests;

use App\Modules\Vehicles\Application\VehicleData;
use App\Modules\Vehicles\Domain\VehicleRelationship;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VehicleRequest extends FormRequest
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
            'plate' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'type' => ['required', Rule::enum(VehicleType::class)],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:40'],
            'year' => ['nullable', 'integer', 'between:1900,'.((int) date('Y') + 1)],
            'is_electric' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Only read when registering: links are managed on their own afterwards.
            'customer_id' => ['nullable', 'ulid'],
            'relationship' => ['nullable', Rule::enum(VehicleRelationship::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.required' => 'La placa es obligatoria.',
            'country.required' => 'Selecciona el país de la placa.',
            'country.size' => 'Selecciona el país de la placa.',
            'type.required' => 'Selecciona el tipo de vehículo.',
            'year.between' => 'El año no es válido.',
            'year.integer' => 'El año no es válido.',
            'customer_id.ulid' => 'Selecciona un cliente válido.',
        ];
    }

    public function toData(): VehicleData
    {
        $year = $this->validated('year');

        return new VehicleData(
            plate: (string) $this->validated('plate'),
            country: (string) $this->validated('country'),
            type: VehicleType::from((string) $this->validated('type')),
            brand: $this->validated('brand'),
            model: $this->validated('model'),
            color: $this->validated('color'),
            year: $year === null ? null : (int) $year,
            isElectric: $this->boolean('is_electric'),
            notes: $this->validated('notes'),
        );
    }

    public function customerId(): ?string
    {
        $customerId = $this->validated('customer_id');

        return is_string($customerId) && $customerId !== '' ? strtolower($customerId) : null;
    }

    public function relationship(): VehicleRelationship
    {
        $relationship = $this->validated('relationship');

        return is_string($relationship) ? VehicleRelationship::from($relationship) : VehicleRelationship::Owner;
    }
}
