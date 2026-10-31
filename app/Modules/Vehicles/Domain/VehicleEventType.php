<?php

declare(strict_types=1);

namespace App\Modules\Vehicles\Domain;

/**
 * Entries of the vehicle timeline. Sessions, reservations and memberships
 * append their own types; LPR reads will in a later version.
 */
enum VehicleEventType: string
{
    case Registered = 'registered';
    case Updated = 'updated';
    case PlateChanged = 'plate_changed';
    case StatusChanged = 'status_changed';
    case CustomerLinked = 'customer_linked';
    case RelationshipChanged = 'relationship_changed';
    case CustomerUnlinked = 'customer_unlinked';
    case Entered = 'entered';
    case Exited = 'exited';
    case SessionCancelled = 'session_cancelled';
    case Reserved = 'reserved';
    case MembershipAdded = 'membership_added';
    case MembershipRemoved = 'membership_removed';
}
