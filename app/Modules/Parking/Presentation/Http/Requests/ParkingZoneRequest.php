<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ParkingZoneRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:300'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la zona es obligatorio.',
            'code.required' => 'El código de la zona es obligatorio.',
        ];
    }
}
