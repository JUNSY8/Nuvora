<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Presentation\Http\Requests;

use App\Modules\Vehicles\Domain\VehicleRelationship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VehicleLinkRequest extends FormRequest
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
            'customer_id' => ['required', 'ulid'],
            'relationship' => ['required', Rule::enum(VehicleRelationship::class)],
            'transfer_ownership' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Selecciona el cliente.',
            'customer_id.ulid' => 'Selecciona un cliente válido.',
            'relationship.required' => 'Selecciona la relación con el vehículo.',
        ];
    }
}
