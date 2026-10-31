<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * What a payment pays. Each type is owned by another module, which
 * registers a PayableSource for it.
 */
enum PayableType: string
{
    case ParkingSession = 'parking_session';
    case MembershipPeriod = 'membership_period';
}
