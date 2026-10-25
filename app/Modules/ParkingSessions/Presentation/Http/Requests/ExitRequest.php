<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Presentation\Http\Requests;

use App\Modules\ParkingSessions\Application\ExitPayment;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExitRequest extends FormRequest
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
            'gate_id' => ['nullable', 'ulid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'discount_id' => ['nullable', 'ulid'],
            'quote_token' => ['nullable', 'string', 'max:2000'],
            'payment' => ['nullable', 'array'],
            'payment.method' => ['required_with:payment', Rule::enum(PaymentMethod::class)],
            'payment.tendered_amount' => ['nullable', 'integer', 'min:0'],
            'payment.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function payment(): ?ExitPayment
    {
        $payment = $this->validated('payment');

        if (! is_array($payment)) {
            return null;
        }

        return new ExitPayment(
            method: PaymentMethod::from((string) $payment['method']),
            tendered: isset($payment['tendered_amount']) ? (int) $payment['tendered_amount'] : null,
            reference: isset($payment['reference']) ? (string) $payment['reference'] : null,
        );
    }

    public function quoteToken(): ?string
    {
        $token = $this->validated('quote_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function discountId(): ?string
    {
        $discount = $this->validated('discount_id');

        return is_string($discount) ? strtolower($discount) : null;
    }

    public function gateId(): ?string
    {
        $gate = $this->validated('gate_id');

        return is_string($gate) ? strtolower($gate) : null;
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) ? $notes : null;
    }
}
