<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use App\Modules\Payments\Domain\PayableType;
use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PaymentLinkRequest extends FormRequest
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
            'payable_type' => ['required', Rule::enum(PayableType::class)],
            'payable_id' => ['required', 'ulid'],
            'account_id' => ['required', 'ulid'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
