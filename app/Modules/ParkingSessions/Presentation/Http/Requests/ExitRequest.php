<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Presentation\Http\Requests;

use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
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
