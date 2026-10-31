<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PayableType;

/**
 * Something that can be paid, as its owning module describes it: how much
 * it costs in total and what the receipt should say. The amount is the full
 * charge; Payments subtracts what was already collected.
 */
final class Payable
{
    /**
     * @param  array<string, mixed>  $details  shown on the receipt; copied into the payment
     */
    public function __construct(
        public readonly PayableType $type,
        public readonly string $id,
        public readonly int $amountDue,
        public readonly ?string $currency,
        public readonly ?string $lotId,
        public readonly ?string $customerId,
        public readonly string $description,
        public readonly ?string $plate,
        public readonly array $details,
        public readonly ?string $notPayableReason = null,
    ) {}
}
