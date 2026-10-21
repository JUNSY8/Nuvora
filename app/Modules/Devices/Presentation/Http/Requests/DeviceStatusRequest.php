<?php

declare(strict_types=1);

namespace App\Modules\Devices\Presentation\Http\Requests;

use App\Modules\Devices\Domain\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeviceStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(DeviceStatus::class)],
        ];
    }
}
