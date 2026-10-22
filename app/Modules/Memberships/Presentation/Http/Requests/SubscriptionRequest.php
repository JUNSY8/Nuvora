<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Presentation\Http\Requests;

use App\Modules\Memberships\Application\SubscriptionData;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubscriptionRequest extends FormRequest
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
            'plan_id' => ['required', 'ulid'],
            'customer_id' => ['required', 'ulid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'vehicles' => ['required', 'array', 'min:1', 'max:500'],
            'vehicles.*.plate' => ['required', 'string', 'max:20'],
            'vehicles.*.country' => ['required', 'string', 'size:2'],
            'vehicles.*.vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'assigned_lot_id' => ['nullable', 'ulid', 'required_with:assigned_space_id'],
            'assigned_space_id' => ['nullable', 'ulid'],
            'auto_renew' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Selecciona el plan.',
            'customer_id.required' => 'Selecciona el cliente titular.',
            'starts_on.required' => 'Indica la fecha de inicio.',
            'vehicles.required' => 'Agrega al menos un vehículo.',
            'vehicles.*.plate.required' => 'Escribe la placa.',
        ];
    }

    public function toData(): SubscriptionData
    {
        $lot = $this->validated('assigned_lot_id');
        $space = $this->validated('assigned_space_id');
        $notes = $this->validated('notes');

        return new SubscriptionData(
            planId: strtolower((string) $this->validated('plan_id')),
            customerId: strtolower((string) $this->validated('customer_id')),
            startsOn: (string) $this->validated('starts_on'),
            vehicles: array_map(fn (array $vehicle): array => [
                'plate' => (string) $vehicle['plate'],
                'country' => (string) $vehicle['country'],
                'type' => isset($vehicle['vehicle_type']) ? VehicleType::from((string) $vehicle['vehicle_type']) : null,
            ], (array) $this->validated('vehicles')),
            assignedLotId: is_string($lot) && $lot !== '' ? strtolower($lot) : null,
            assignedSpaceId: is_string($space) && $space !== '' ? strtolower($space) : null,
            autoRenew: $this->boolean('auto_renew', true),
            notes: is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
        );
    }
}
