<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

final class PriceResult
{
    public readonly int $total;

    /**
     * @param  list<PriceLine>  $lines
     */
    public function __construct(
        public readonly int $stayMinutes,
        public readonly int $graceMinutes,
        public readonly int $chargedMinutes,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly array $lines,
        public readonly bool $graceApplied,
        public readonly ?DiscountRule $discountRule,
    ) {
        $this->total = $subtotal - $discount;
    }
}
