<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * Life of a payment. Offline payments (cash and manual) complete at once.
 * Pending and failed are for online providers (0.10), whose answer arrives
 * later.
 *
 *   pending ─► completed ─► partially_refunded ─► refunded
 *      │            └───────────────────────────────┘
 *      └────► failed
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * Statuses in which the money was received, whether or not part of it
     * was refunded later.
     *
     * @return list<string>
     */
    public static function collectedValues(): array
    {
        return [self::Completed->value, self::PartiallyRefunded->value, self::Refunded->value];
    }

    public function isCollected(): bool
    {
        return in_array($this->value, self::collectedValues(), true);
    }

    /**
     * The status a provider's answer moves this payment to, or null when it
     * changes nothing. Answers can arrive late, twice or out of order: a
     * pending answer never undoes a final one, and an approval that comes
     * after the payment was given up (expired, declined) still counts,
     * because the money was taken. A void of a received payment is a
     * refund and is recorded as one, not here.
     */
    public function afterProvider(ProviderStatus $answer): ?self
    {
        return match (true) {
            $this === self::Pending && $answer === ProviderStatus::Approved => self::Completed,
            $this === self::Pending && in_array($answer, [ProviderStatus::Declined, ProviderStatus::Expired, ProviderStatus::Error, ProviderStatus::Voided], true) => self::Failed,
            $this === self::Failed && $answer === ProviderStatus::Approved => self::Completed,
            default => null,
        };
    }

    /**
     * The status once the refunded total is what it is now (after a failed
     * refund gives its amount back).
     */
    public static function forRefunded(int $amount, int $refunded): self
    {
        return match (true) {
            $refunded <= 0 => self::Completed,
            $refunded >= $amount => self::Refunded,
            default => self::PartiallyRefunded,
        };
    }

    /**
     * The status after refunding $refund on top of what was already refunded.
     */
    public function afterRefund(int $amount, int $alreadyRefunded, int $refund): self
    {
        if (! in_array($this, [self::Completed, self::PartiallyRefunded], true)) {
            throw new PaymentRuleViolation('Solo se puede reembolsar un pago recibido que no se haya devuelto por completo.', 'PAYMENT_NOT_REFUNDABLE', 409);
        }

        if ($refund <= 0) {
            throw new PaymentRuleViolation('El valor a reembolsar debe ser mayor que cero.', 'INVALID_AMOUNT');
        }

        $total = $alreadyRefunded + $refund;

        if ($total > $amount) {
            throw new PaymentRuleViolation('El reembolso supera lo que queda por devolver de este pago.', 'REFUND_EXCEEDS_PAYMENT');
        }

        return $total === $amount ? self::Refunded : self::PartiallyRefunded;
    }
}
