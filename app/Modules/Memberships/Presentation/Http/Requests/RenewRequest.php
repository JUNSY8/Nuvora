<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Presentation\Http\Requests;

use App\Modules\Shared\Presentation\Http\Concerns\ReadsIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A renewal has no body; its optional Idempotency-Key header makes a retry
 * return the first result.
 */
final class RenewRequest extends FormRequest
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
        return [];
    }
}
