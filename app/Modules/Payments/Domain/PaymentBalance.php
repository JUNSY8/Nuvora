<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * What a payable owes against what was collected for it, with refunds
 * already subtracted. A payable that owes nothing (covered by a membership,
 * free within the grace time, unpriced) does not require payment.
 */
final class PaymentBalance
{
    public function __construct(
        public readonly int $due,
        public readonly int $paid,
    ) {}

    public function outstanding(): int
    {
        return max(0, $this->due - $this->paid);
    }

    public function status(): string
    {
        return match (true) {
            $this->due <= 0 => 'not_required',
            $this->paid <= 0 => 'pending',
            $this->paid < $this->due => 'partial',
            default => 'paid',
        };
    }

    public function isSettled(): bool
    {
        return $this->due > 0 && $this->paid >= $this->due;
    }

    /**
     * @return array{status: string, due: int, paid: int, balance: int}
     */
    public function toArray(): array
    {
        return ['status' => $this->status(), 'due' => $this->due, 'paid' => $this->paid, 'balance' => $this->outstanding()];
    }
}
