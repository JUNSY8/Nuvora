<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation\Http\Requests;

use App\Modules\Customers\Domain\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(CustomerStatus::class)],
        ];
    }
}
