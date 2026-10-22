<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Domain;

/**
 * Outcome of pricing a closed stay. Unpriced stays closed without an
 * applicable tariff (or before pricing existed) and carry no amounts. A
 * covered stay is paid by a membership: its total is zero and the subtotal
 * records what the tariff would have charged.
 */
enum ChargeStatus: string
{
    case Priced = 'priced';
    case Unpriced = 'unpriced';
    case Covered = 'covered';
}
