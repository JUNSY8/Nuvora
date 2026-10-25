<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use App\Modules\Payments\Application\PaymentData;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PaymentRequest extends FormRequest
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
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['nullable', 'integer', 'min:1'],
            'tendered_amount' => ['nullable', 'integer', 'min:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): PaymentData
    {
        $validated = $this->validated();

        return new PaymentData(
            payableType: PayableType::from((string) $validated['payable_type']),
            payableId: strtolower((string) $validated['payable_id']),
            method: PaymentMethod::from((string) $validated['method']),
            amount: isset($validated['amount']) ? (int) $validated['amount'] : null,
            tendered: isset($validated['tendered_amount']) ? (int) $validated['tendered_amount'] : null,
            reference: isset($validated['reference']) ? (string) $validated['reference'] : null,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
        );
    }
}
