<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Domain;

/**
 * Who identified the vehicle at the gate. Version 0.5 is operated by hand;
 * LPR, QR and Edge devices add their sources in later versions.
 */
enum SessionSource: string
{
    case Manual = 'manual';
}
