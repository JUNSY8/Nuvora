<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

/**
 * Percentage values are basis points (2000 = 20 %), fixed amounts are minor
 * units of the discount's currency and free minutes are taken off the
 * chargeable stay before the tariff is applied.
 */
enum DiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case FreeMinutes = 'free_minutes';
}
