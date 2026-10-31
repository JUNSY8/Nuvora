<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Requests;

use App\Modules\Parking\Domain\OperatingDayMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OperatingHoursRequest extends FormRequest
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
            'days' => ['required', 'array', 'size:7'],
            'days.*.day_of_week' => ['required', 'integer', 'between:1,7', 'distinct'],
            'days.*.mode' => ['required', Rule::enum(OperatingDayMode::class)],
            'days.*.opens_at' => ['nullable', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'days.size' => 'El horario debe incluir los siete días.',
            'days.*.day_of_week.distinct' => 'Cada día debe aparecer una sola vez.',
            'days.*.opens_at.date_format' => 'La hora de apertura debe tener el formato HH:MM.',
            'days.*.closes_at.date_format' => 'La hora de cierre debe tener el formato HH:MM.',
        ];
    }
}
