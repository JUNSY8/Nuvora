<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

/**
 * Tariffs and discounts are deactivated, never deleted: closed sessions
 * keep pointing to the version that priced them.
 */
enum TariffStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
