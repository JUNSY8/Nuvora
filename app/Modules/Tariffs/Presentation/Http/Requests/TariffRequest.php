<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Presentation\Http\Requests;

use App\Modules\Tariffs\Application\TariffData;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Amounts are integers in the minor unit of the lot's currency (5.000 COP
 * is 500000). The domain validates the shape of brackets and windows.
 */
final class TariffRequest extends FormRequest
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
            'parking_lot_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'ulid'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:80'],
            'priority' => ['sometimes', 'integer', 'between:-100,100'],
            'vehicle_types' => ['present', 'array'],
            'vehicle_types.*' => ['distinct', Rule::enum(VehicleType::class)],
            'zone_id' => ['nullable', 'ulid'],
            'windows' => ['present', 'array', 'max:14'],
            'windows.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'windows.*.days.*' => ['integer', 'between:1,7'],
            'windows.*.start' => ['required', 'date_format:H:i'],
            'windows.*.end' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['nullable', 'integer', 'between:0,240'],
            'brackets' => ['present', 'array', 'max:12'],
            'brackets.*.up_to_minutes' => ['required', 'integer', 'min:1'],
            'brackets.*.amount' => ['required', 'integer', 'min:0'],
            'step_minutes' => ['required', 'integer', 'between:1,44640'],
            'step_amount' => ['required', 'integer', 'min:0'],
            'daily_max_amount' => ['nullable', 'integer', 'min:1'],
            'change_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parking_lot_id.required' => 'Selecciona el parqueadero de la tarifa.',
            'parking_lot_id.prohibited' => 'Una tarifa no cambia de parqueadero; crea una nueva en el otro.',
            'code.required' => 'El código de la tarifa es obligatorio.',
            'name.required' => 'El nombre de la tarifa es obligatorio.',
            'step_minutes.required' => 'Indica cada cuántos minutos se cobra la fracción adicional.',
            'step_amount.required' => 'Indica el valor de la fracción adicional.',
            'windows.*.start.date_format' => 'Las horas de una franja usan el formato HH:MM.',
            'windows.*.end.date_format' => 'Las horas de una franja usan el formato HH:MM.',
            'windows.*.days.required' => 'Cada franja debe incluir al menos un día.',
            'grace_minutes.between' => 'El tiempo de gracia debe estar entre 0 y 240 minutos.',
        ];
    }

    public function lotId(): string
    {
        return strtolower((string) $this->validated('parking_lot_id'));
    }

    public function toData(): TariffData
    {
        $zoneId = $this->validated('zone_id');
        $grace = $this->validated('grace_minutes');
        $dailyMax = $this->validated('daily_max_amount');
        $note = $this->validated('change_note');

        return new TariffData(
            code: (string) $this->validated('code'),
            name: (string) $this->validated('name'),
            priority: (int) $this->validated('priority', 0),
            vehicleTypes: array_map(fn (mixed $type): VehicleType => VehicleType::from((string) $type), (array) $this->validated('vehicle_types')),
            zoneId: is_string($zoneId) ? strtolower($zoneId) : null,
            windows: array_map(fn (array $window): array => [
                'days' => array_map('intval', $window['days']),
                'start' => (string) $window['start'],
                'end' => (string) $window['end'],
            ], (array) $this->validated('windows')),
            graceMinutes: $grace === null ? null : (int) $grace,
            brackets: array_map(fn (array $bracket): array => [
                'up_to_minutes' => (int) $bracket['up_to_minutes'],
                'amount' => (int) $bracket['amount'],
            ], (array) $this->validated('brackets')),
            stepMinutes: (int) $this->validated('step_minutes'),
            stepAmount: (int) $this->validated('step_amount'),
            dailyMaxAmount: $dailyMax === null ? null : (int) $dailyMax,
            changeNote: is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
    }
}
