<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RefundRequest extends FormRequest
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
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            // Online payments: through the provider (default) or by hand outside Nuvora.
            'channel' => ['nullable', Rule::in(['provider', 'manual'])],
            'manual_method' => ['nullable', Rule::in(['cash', 'transfer', 'other'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indica el motivo del reembolso.',
            'reason.min' => 'Describe el motivo con al menos 3 caracteres.',
        ];
    }

    public function channel(): ?string
    {
        $channel = $this->validated('channel');

        return is_string($channel) ? $channel : null;
    }

    public function manualMethod(): ?PaymentMethod
    {
        $method = $this->validated('manual_method');

        return is_string($method) ? PaymentMethod::from($method) : null;
    }

    public function amount(): ?int
    {
        $amount = $this->validated('amount');

        return $amount === null ? null : (int) $amount;
    }
}
