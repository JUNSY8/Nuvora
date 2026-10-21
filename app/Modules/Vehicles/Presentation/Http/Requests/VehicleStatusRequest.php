<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Presentation\Http\Requests;

use App\Modules\Vehicles\Domain\VehicleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VehicleStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(VehicleStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
