<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MoveSessionRequest extends FormRequest
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
            'space_id' => ['required', 'ulid'],
        ];
    }
}
