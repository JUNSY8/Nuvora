<?php

declare(strict_types=1);

namespace App\Modules\Devices\Presentation\Http\Requests;

use App\Modules\Devices\Application\DeviceData;
use App\Modules\Devices\Domain\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Credentials are intentionally not accepted: they will live encrypted in
     * Nuvora Edge, never in this registry.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parking_lot_id' => ['required', 'ulid'],
            'gate_id' => ['nullable', 'ulid'],
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:40'],
            'type' => ['required', Rule::enum(DeviceType::class)],
            'vendor' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'ip_address' => ['nullable', 'ip'],
            'firmware_version' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parking_lot_id.required' => 'Selecciona el parqueadero del dispositivo.',
            'parking_lot_id.ulid' => 'Selecciona un parqueadero válido.',
            'gate_id.ulid' => 'Selecciona una puerta válida.',
            'name.required' => 'El nombre del dispositivo es obligatorio.',
            'code.required' => 'El código del dispositivo es obligatorio.',
            'type.required' => 'Selecciona el tipo de dispositivo.',
            'ip_address.ip' => 'La dirección IP no es válida.',
        ];
    }

    public function toData(): DeviceData
    {
        $gateId = $this->validated('gate_id');

        return new DeviceData(
            parkingLotId: strtolower((string) $this->validated('parking_lot_id')),
            gateId: is_string($gateId) && $gateId !== '' ? strtolower($gateId) : null,
            name: (string) $this->validated('name'),
            code: (string) $this->validated('code'),
            type: DeviceType::from((string) $this->validated('type')),
            vendor: $this->validated('vendor'),
            model: $this->validated('model'),
            serialNumber: $this->validated('serial_number'),
            ipAddress: $this->validated('ip_address'),
            firmwareVersion: $this->validated('firmware_version'),
        );
    }
}
