<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Presentation\Http\Requests;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Reservations\Application\ReservationData;
use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Vehicles\Domain\VehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Start and end are whole minutes in the lot's local time
 * ("2026-11-04T10:00"); they are converted to UTC here.
 */
final class ReservationRequest extends FormRequest
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
            'customer_id' => ['nullable', 'ulid'],
            'zone_id' => ['nullable', 'ulid'],
            'space_id' => ['nullable', 'ulid'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'confirm' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lot_id.required' => 'Selecciona el parqueadero.',
            'plate.required' => 'La placa es obligatoria.',
            'starts_at.required' => 'Indica la hora de llegada.',
            'ends_at.required' => 'Indica la hora de salida.',
            'starts_at.date_format' => 'La hora de llegada no es válida.',
            'ends_at.date_format' => 'La hora de salida no es válida.',
        ];
    }

    public function toData(ParkingInfrastructureDirectory $parking, TenantContext $context): ReservationData
    {
        $lotId = strtolower((string) $this->validated('lot_id'));
        $lot = $parking->lot((string) $context->id(), $lotId)
            ?? throw ValidationException::withMessages(['lot_id' => 'Selecciona un parqueadero de esta empresa.']);
        $type = $this->validated('vehicle_type');
        $notes = $this->validated('notes');

        return new ReservationData(
            lotId: $lotId,
            plate: (string) $this->validated('plate'),
            country: (string) $this->validated('country'),
            vehicleType: is_string($type) ? VehicleType::from($type) : null,
            customerId: self::id($this->validated('customer_id')),
            zoneId: self::id($this->validated('zone_id')),
            spaceId: self::id($this->validated('space_id')),
            startsAt: self::local((string) $this->validated('starts_at'), $lot['timezone']),
            endsAt: self::local((string) $this->validated('ends_at'), $lot['timezone']),
            confirm: $this->boolean('confirm', true),
            notes: is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            idempotencyKey: $this->idempotencyKey(),
        );
    }

    public static function local(string $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone)->startOfMinute()->utc();
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }
}
