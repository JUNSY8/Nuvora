<?php

namespace Tests\Unit;

use App\Modules\Parking\Domain\SpaceType;
use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\ParkingSessions\Domain\SpaceCompatibility;
use App\Modules\ParkingSessions\Domain\StayDuration;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ParkingSessionRulesTest extends TestCase
{
    public function test_an_open_session_ends_once_and_ended_sessions_are_final(): void
    {
        $this->assertTrue(SessionStatus::Open->canTransitionTo(SessionStatus::Closed));
        $this->assertTrue(SessionStatus::Open->canTransitionTo(SessionStatus::Cancelled));
        $this->assertFalse(SessionStatus::Open->canTransitionTo(SessionStatus::Open));

        foreach ([SessionStatus::Closed, SessionStatus::Cancelled] as $ended) {
            $this->assertSame([], $ended->transitions());
        }
    }

    public function test_stay_duration_counts_started_minutes(): void
    {
        $entry = new DateTimeImmutable('2026-11-04 10:00:00');

        $this->assertSame(0, StayDuration::minutes($entry, $entry));
        $this->assertSame(1, StayDuration::minutes($entry, $entry->modify('+1 second')));
        $this->assertSame(1, StayDuration::minutes($entry, $entry->modify('+60 seconds')));
        $this->assertSame(2, StayDuration::minutes($entry, $entry->modify('+61 seconds')));
        $this->assertSame(1440, StayDuration::minutes($entry, $entry->modify('+1 day')));
        // A clock that moved backwards never produces a negative stay.
        $this->assertSame(0, StayDuration::minutes($entry, $entry->modify('-5 minutes')));
    }

    public function test_automatic_assignment_prefers_the_vehicle_size_and_charging_spaces(): void
    {
        $this->assertSame([SpaceType::Car], SpaceCompatibility::preferred(VehicleType::Car, false));
        $this->assertSame([SpaceType::Ev, SpaceType::Car], SpaceCompatibility::preferred(VehicleType::Car, true));
        $this->assertSame([SpaceType::Motorcycle], SpaceCompatibility::preferred(VehicleType::Bicycle, false));
        $this->assertSame([SpaceType::Truck], SpaceCompatibility::preferred(VehicleType::Bus, false));

        // Special spaces are never assigned automatically.
        foreach (VehicleType::cases() as $vehicle) {
            foreach ([true, false] as $electric) {
                $preferred = SpaceCompatibility::preferred($vehicle, $electric);

                $this->assertNotContains(SpaceType::Disabled, $preferred);
                $this->assertNotContains(SpaceType::Vip, $preferred);
                $this->assertNotContains(SpaceType::Reserved, $preferred);

                // Whatever is assigned automatically must also be acceptable by hand.
                foreach ($preferred as $type) {
                    $this->assertTrue(SpaceCompatibility::accepts($type, $vehicle), "{$vehicle->value} → {$type->value}");
                }
            }
        }
    }

    public function test_manual_choice_rejects_spaces_that_do_not_fit(): void
    {
        $this->assertTrue(SpaceCompatibility::accepts(SpaceType::Disabled, VehicleType::Car));
        $this->assertTrue(SpaceCompatibility::accepts(SpaceType::Car, VehicleType::Motorcycle));
        $this->assertFalse(SpaceCompatibility::accepts(SpaceType::Motorcycle, VehicleType::Car));
        $this->assertFalse(SpaceCompatibility::accepts(SpaceType::Motorcycle, VehicleType::Truck));
        $this->assertFalse(SpaceCompatibility::accepts(SpaceType::Car, VehicleType::Bus));
        $this->assertFalse(SpaceCompatibility::accepts(SpaceType::Truck, VehicleType::Motorcycle));
    }
}
