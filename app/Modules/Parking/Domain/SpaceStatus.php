<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

enum SpaceStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';
    case OutOfService = 'out_of_service';

    /**
     * Every transition the space state machine accepts, whoever requests it.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Available => [self::Occupied, self::Reserved, self::Blocked, self::Maintenance, self::OutOfService],
            self::Occupied => [self::Available],
            self::Reserved => [self::Occupied, self::Available],
            self::Blocked => [self::Available, self::Maintenance, self::OutOfService],
            self::Maintenance => [self::Available, self::Blocked, self::OutOfService],
            self::OutOfService => [self::Available, self::Maintenance],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    /**
     * Occupied and reserved belong to parking sessions and reservations. An
     * administrator can only move a space between the operational states.
     */
    public function isManagedManually(): bool
    {
        return in_array($this, [self::Available, self::Blocked, self::Maintenance, self::OutOfService], true);
    }

    /**
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        if (! $this->isManagedManually()) {
            return [];
        }

        return array_values(array_filter(
            $this->transitions(),
            fn (self $target): bool => $target->isManagedManually(),
        ));
    }

    public function countsTowardsCapacity(): bool
    {
        return $this !== self::OutOfService;
    }

    public function isRemovable(): bool
    {
        return $this->isManagedManually();
    }
}
