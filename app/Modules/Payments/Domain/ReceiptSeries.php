<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * Consecutive numbering per tenant. Each series counts on its own, and the
 * counter row is locked while numbering, so a committed number is never
 * repeated.
 */
enum ReceiptSeries: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Reconciliation = 'reconciliation';

    public function prefix(): string
    {
        return match ($this) {
            self::Payment => 'RC',
            self::Refund => 'DV',
            self::Reconciliation => 'CC',
        };
    }

    public function format(int $number): string
    {
        return $this->prefix().'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
