<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Requests;

use App\Modules\Parking\Domain\GateDirection;
use App\Modules\Parking\Domain\GateStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ParkingGateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:40'],
            'direction' => ['required', Rule::enum(GateDirection::class)],
            'status' => ['required', Rule::enum(GateStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la puerta es obligatorio.',
            'code.required' => 'El código de la puerta es obligatorio.',
        ];
    }
}
