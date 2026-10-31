<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * State of a transaction as a payment provider reports it, normalized from
 * each provider's own codes (Wompi APPROVED, PayU state_pol 4...).
 */
enum ProviderStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Declined = 'declined';
    case Expired = 'expired';
    case Error = 'error';
    case Voided = 'voided';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'aprobada',
            self::Pending => 'pendiente',
            self::Declined => 'rechazada',
            self::Expired => 'vencida',
            self::Error => 'con error',
            self::Voided => 'anulada',
        };
    }
}
