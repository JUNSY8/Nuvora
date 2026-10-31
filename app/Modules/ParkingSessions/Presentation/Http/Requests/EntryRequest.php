<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Presentation\Http\Requests;

use App\Modules\ParkingSessions\Application\EntryData;
use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use App\Modules\Vehicles\Domain\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EntryRequest extends FormRequest
{
    use ReadsIdempotencyKey;

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
            'lot_id' => ['required', 'ulid'],
            'plate' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'space_id' => ['nullable', 'ulid'],
            'auto_assign' => ['sometimes', 'boolean'],
            'gate_id' => ['nullable', 'ulid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): EntryData
    {
        $type = $this->validated('vehicle_type');
        $notes = $this->validated('notes');

        return new EntryData(
            lotId: strtolower((string) $this->validated('lot_id')),
            plate: (string) $this->validated('plate'),
            country: (string) $this->validated('country'),
            vehicleType: is_string($type) ? VehicleType::from($type) : null,
            spaceId: self::id($this->validated('space_id')),
            autoAssign: $this->boolean('auto_assign', true),
            gateId: self::id($this->validated('gate_id')),
            notes: is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            idempotencyKey: $this->idempotencyKey(),
        );
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) ? strtolower($value) : null;
    }
}
