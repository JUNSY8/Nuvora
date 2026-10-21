<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Requests;

final class ParkingSpaceRangeRequest extends ParkingSpaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:20'],
            'from' => ['required', 'integer', 'between:0,99999'],
            'to' => ['required', 'integer', 'between:0,99999', 'gte:from'],
            'padding' => ['nullable', 'integer', 'between:0,6'],
            ...self::attributeRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'from.required' => 'Indica el número inicial.',
            'to.required' => 'Indica el número final.',
            'to.gte' => 'El número final debe ser mayor o igual al inicial.',
        ];
    }
}
