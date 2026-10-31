<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

/**
 * Lifecycle of a membership:
 *
 *   active ◄──► suspended
 *     │  ▲         │
 *     │  └ renew ┐ │
 *     ├► expired ┘ │
 *     └► cancelled ◄┘
 *
 * Expired memberships can be renewed; cancelled ones are final.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Active => [self::Suspended, self::Expired, self::Cancelled],
            self::Suspended => [self::Active, self::Expired, self::Cancelled],
            self::Expired => [self::Active],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Statuses that hold a vehicle, an assigned space and plan capacity.
     *
     * @return list<string>
     */
    public static function holdingValues(): array
    {
        return [self::Active->value, self::Suspended->value];
    }
}
