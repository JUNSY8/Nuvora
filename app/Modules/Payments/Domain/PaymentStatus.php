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
