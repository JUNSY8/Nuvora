<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

/**
 * One line of the explanation stored with a charge. Amounts are in minor
 * units; a discount line is negative. The label is kept so a closed session
 * stays explainable even if the tariff changes later.
 */
final class PriceLine
{
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly int $amount,
        public readonly ?int $quantity = null,
        public readonly ?int $unitAmount = null,
    ) {}

    /**
     * @return array{type: string, label: string, amount: int, quantity: int|null, unit_amount: int|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'amount' => $this->amount,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount,
        ];
    }
}
