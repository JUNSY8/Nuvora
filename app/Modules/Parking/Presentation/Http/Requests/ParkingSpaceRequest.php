<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Requests;

use App\Modules\Parking\Application\ParkingSpaceData;
use App\Modules\Parking\Domain\SpaceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParkingSpaceRequest extends FormRequest
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
            ...self::attributeRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function attributeRules(): array
    {
        return [
            'zone_id' => ['nullable', 'ulid'],
            'type' => ['required', Rule::enum(SpaceType::class)],
            'floor' => ['nullable', 'string', 'max:20'],
            'is_reservable' => ['required', 'boolean'],
            'is_assignable' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código del espacio es obligatorio.',
            'type.required' => 'Selecciona el tipo de espacio.',
            'zone_id.ulid' => 'La zona no es válida.',
        ];
    }

    public function toData(): ParkingSpaceData
    {
        $zoneId = $this->validated('zone_id');
        $floor = $this->validated('floor');

        return new ParkingSpaceData(
            zoneId: is_string($zoneId) && $zoneId !== '' ? strtolower($zoneId) : null,
            type: SpaceType::from((string) $this->validated('type')),
            floor: is_string($floor) && trim($floor) !== '' ? trim($floor) : null,
            isReservable: (bool) $this->validated('is_reservable'),
            isAssignable: (bool) $this->validated('is_assignable'),
        );
    }
}
