<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Entries, exits and bookings accept an optional Idempotency-Key header.
 * The interface sends one per attempt and reuses it on retries, so a request
 * repeated after a timeout returns the original result instead of acting
 * twice.
 */
trait ReadsIdempotencyKey
{
    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $key = trim($key);

        if (preg_match('/^[A-Za-z0-9_\-:.]{8,80}$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia debe tener entre 8 y 80 letras, dígitos o guiones.',
            ]);
        }

        return $key;
    }
}
