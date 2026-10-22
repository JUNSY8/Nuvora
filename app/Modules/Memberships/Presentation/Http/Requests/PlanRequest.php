<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Presentation\Http\Requests;

use App\Modules\Memberships\Application\PlanData;
use App\Modules\Memberships\Domain\BillingPeriod;
use App\Modules\Memberships\Domain\PlanCategory;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The price is an integer in the minor unit of its currency (120.000 COP is
 * 12000000). Empty lists of lots, zones, vehicle types or windows mean no
 * restriction.
 */
final class PlanRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:80'],
            'category' => ['required', Rule::enum(PlanCategory::class)],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],
            'price_amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'grace_days' => ['required', 'integer', 'between:0,31'],
            'max_vehicles' => ['required', 'integer', 'between:1,500'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'parking_lot_ids' => ['present', 'array'],
            'parking_lot_ids.*' => ['ulid', 'distinct'],
            'zone_ids' => ['present', 'array'],
            'zone_ids.*' => ['ulid', 'distinct'],
            'vehicle_types' => ['present', 'array'],
            'vehicle_types.*' => ['distinct', Rule::enum(VehicleType::class)],
            'windows' => ['present', 'array', 'max:14'],
            'windows.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'windows.*.days.*' => ['integer', 'between:1,7'],
            'windows.*.start' => ['required', 'date_format:H:i'],
            'windows.*.end' => ['required', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código del plan es obligatorio.',
            'name.required' => 'El nombre del plan es obligatorio.',
            'price_amount.required' => 'Indica el precio del plan.',
            'max_vehicles.between' => 'El plan cubre entre 1 y 500 vehículos.',
            'grace_days.between' => 'Los días de gracia van de 0 a 31.',
        ];
    }

    public function toData(): PlanData
    {
        $capacity = $this->validated('capacity');
        $description = $this->validated('description');

        return new PlanData(
            code: (string) $this->validated('code'),
            name: (string) $this->validated('name'),
            category: PlanCategory::from((string) $this->validated('category')),
            billingPeriod: BillingPeriod::from((string) $this->validated('billing_period')),
            priceAmount: (int) $this->validated('price_amount'),
            currency: (string) $this->validated('currency'),
            graceDays: (int) $this->validated('grace_days'),
            maxVehicles: (int) $this->validated('max_vehicles'),
            capacity: $capacity === null ? null : (int) $capacity,
            lotIds: array_map(fn (mixed $id): string => strtolower((string) $id), (array) $this->validated('parking_lot_ids')),
            zoneIds: array_map(fn (mixed $id): string => strtolower((string) $id), (array) $this->validated('zone_ids')),
            vehicleTypes: array_map(fn (mixed $type): VehicleType => VehicleType::from((string) $type), (array) $this->validated('vehicle_types')),
            windows: array_map(fn (array $window): array => [
                'days' => array_map('intval', $window['days']),
                'start' => (string) $window['start'],
                'end' => (string) $window['end'],
            ], (array) $this->validated('windows')),
            description: is_string($description) && trim($description) !== '' ? trim($description) : null,
        );
    }
}
