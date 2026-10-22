<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

/**
 * Prices one stay with one tariff version. Pure and deterministic: the same
 * stay, tariff and discounts always give the same result, which is what a
 * closed session stores to explain its charge.
 *
 * - A stay within the grace period is free and takes no discount.
 * - Discounts never stack. The one the cashier chose applies; without one,
 *   the automatic promotion that saves the most applies (ties keep the
 *   first in the order received).
 * - The total never goes below zero.
 */
final class PriceCalculator
{
    /**
     * @param  list<DiscountRule>  $promotions  automatic discounts already known to be available
     */
    public static function calculate(
        TariffDefinition $tariff,
        int $stayMinutes,
        int $lotGraceMinutes,
        string $currency,
        ?DiscountRule $manual = null,
        array $promotions = [],
    ): PriceResult {
        $grace = $tariff->graceMinutes ?? $lotGraceMinutes;

        if ($stayMinutes <= $grace) {
            return new PriceResult(
                stayMinutes: $stayMinutes,
                graceMinutes: $grace,
                chargedMinutes: 0,
                subtotal: 0,
                discount: 0,
                lines: [new PriceLine('grace', "Dentro del tiempo de gracia ({$grace} min)", 0)],
                graceApplied: true,
                discountRule: null,
            );
        }

        $base = $tariff->rates->charge($stayMinutes);
        $chosen = null;
        $reduction = 0;
        $chargedMinutes = $stayMinutes;

        foreach ($manual === null ? $promotions : [$manual] as $candidate) {
            $minutes = $candidate->type === DiscountType::FreeMinutes ? max(0, $stayMinutes - $candidate->value) : $stayMinutes;
            $reducedPrice = $minutes === $stayMinutes ? $base['amount'] : $tariff->rates->charge($minutes)['amount'];
            $saving = $candidate->reduction($base['amount'], $reducedPrice, $currency);

            // The cashier's choice is recorded even when it saves nothing; a promotion only when it saves.
            if ($candidate === $manual || $saving > $reduction) {
                $chosen = $candidate;
                $reduction = $saving;
                $chargedMinutes = $minutes;
            }
        }

        $lines = $base['lines'];

        if ($chosen !== null) {
            $lines[] = new PriceLine('discount', $chosen->label(), -$reduction);
        }

        return new PriceResult(
            stayMinutes: $stayMinutes,
            graceMinutes: $grace,
            chargedMinutes: $chargedMinutes,
            subtotal: $base['amount'],
            discount: $reduction,
            lines: $lines,
            graceApplied: false,
            discountRule: $chosen,
        );
    }
}
