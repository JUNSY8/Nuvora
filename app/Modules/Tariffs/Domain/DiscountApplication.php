<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

/**
 * Manual discounts (agreements, courtesy) are chosen by the cashier at the
 * exit. Automatic ones are promotions applied whenever they are valid.
 */
enum DiscountApplication: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
