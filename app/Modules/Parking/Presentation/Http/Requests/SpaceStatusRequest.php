<?php

declare(strict_types=1);

namespace App\Modules\Parking\Presentation\Http\Requests;

use App\Modules\Parking\Domain\SpaceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SpaceStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(SpaceStatus::class)],
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
