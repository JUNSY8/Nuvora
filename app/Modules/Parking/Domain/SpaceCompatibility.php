<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

use App\Modules\Vehicles\Domain\VehicleType;

/**
 * Which spaces a vehicle may use. Automatic assignment only uses general
 * spaces of the vehicle's size; disabled, VIP and reserved spaces are a
 * decision of the operator, who can pick them by hand.
 */
final class SpaceCompatibility
{
    /**
     * Space types tried by automatic assignment, in order of preference.
     *
     * @return list<SpaceType>
     */
    public static function preferred(VehicleType $vehicle, bool $isElectric): array
    {
        return match ($vehicle) {
            VehicleType::Car => $isElectric ? [SpaceType::Ev, SpaceType::Car] : [SpaceType::Car],
            VehicleType::Motorcycle, VehicleType::Bicycle => [SpaceType::Motorcycle],
            VehicleType::Van => $isElectric ? [SpaceType::Ev, SpaceType::Van, SpaceType::Car] : [SpaceType::Van, SpaceType::Car],
            VehicleType::Truck, VehicleType::Bus => [SpaceType::Truck],
            VehicleType::Other => [SpaceType::Car],
        };
    }

    /**
     * Space types an operator may choose by hand for the vehicle.
     *
     * @return list<SpaceType>
     */
    public static function allowed(VehicleType $vehicle): array
    {
        $special = [SpaceType::Disabled, SpaceType::Vip, SpaceType::Reserved];

        return match ($vehicle) {
            VehicleType::Car, VehicleType::Other => [SpaceType::Car, SpaceType::Ev, ...$special],
            VehicleType::Motorcycle, VehicleType::Bicycle => [SpaceType::Motorcycle, SpaceType::Car, ...$special],
            VehicleType::Van => [SpaceType::Van, SpaceType::Car, SpaceType::Ev, SpaceType::Truck, ...$special],
            VehicleType::Truck, VehicleType::Bus => [SpaceType::Truck],
        };
    }

    public static function accepts(SpaceType $space, VehicleType $vehicle): bool
    {
        return in_array($space, self::allowed($vehicle), true);
    }
}
