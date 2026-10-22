<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Domain;

/**
 * Outcome of pricing a closed stay. Unpriced stays closed without an
 * applicable tariff (or before pricing existed) and carry no amounts.
 */
enum ChargeStatus: string
{
    case Priced = 'priced';
    case Unpriced = 'unpriced';
}
