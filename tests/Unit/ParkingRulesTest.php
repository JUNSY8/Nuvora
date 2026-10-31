<?php

namespace Tests\Unit;

use App\Modules\Devices\Domain\DeviceStatus;
use App\Modules\Parking\Domain\Occupancy;
use App\Modules\Parking\Domain\OperatingDay;
use App\Modules\Parking\Domain\OperatingDayMode;
use App\Modules\Parking\Domain\ParkingCode;
use App\Modules\Parking\Domain\ParkingRuleViolation;
use App\Modules\Parking\Domain\SpaceCodeRange;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Domain\WeeklySchedule;
use PHPUnit\Framework\TestCase;

class ParkingRulesTest extends TestCase
{
    public function test_manual_transitions_never_touch_occupied_or_reserved_spaces(): void
    {
        $this->assertSame(
            [SpaceStatus::Blocked, SpaceStatus::Maintenance, SpaceStatus::OutOfService],
            SpaceStatus::Available->manualTransitions(),
        );
        $this->assertSame([], SpaceStatus::Occupied->manualTransitions());
        $this->assertSame([], SpaceStatus::Reserved->manualTransitions());
        $this->assertFalse(SpaceStatus::Blocked->canTransitionTo(SpaceStatus::Occupied));
        $this->assertTrue(SpaceStatus::Reserved->canTransitionTo(SpaceStatus::Occupied));
        $this->assertFalse(SpaceStatus::Occupied->isRemovable());

        foreach (SpaceStatus::cases() as $status) {
            $this->assertNotContains($status, $status->transitions(), 'A state must not transition to itself.');
        }
    }

    public function test_occupancy_excludes_out_of_service_spaces_from_capacity(): void
    {
        $occupancy = Occupancy::fromCounts([
            'available' => 5,
            'occupied' => 3,
            'reserved' => 1,
            'out_of_service' => 2,
            'unknown' => 9,
        ]);

        $this->assertSame(11, $occupancy->total);
        $this->assertSame(9, $occupancy->capacity);
        $this->assertSame(4, $occupancy->inUse);
        $this->assertSame(44, $occupancy->rate());
        $this->assertArrayNotHasKey('unknown', $occupancy->byStatus);
        $this->assertSame(0, Occupancy::fromCounts([])->rate());
    }

    public function test_operating_day_accepts_overnight_hours_and_rejects_empty_windows(): void
    {
        $night = OperatingDay::create(5, OperatingDayMode::Scheduled, '18:00', '02:00:00');
        $this->assertTrue($night->crossesMidnight());
        $this->assertSame('02:00', $night->closesAt);

        $closed = OperatingDay::create(7, OperatingDayMode::Closed, '08:00', '18:00');
        $this->assertNull($closed->opensAt);

        $this->expectException(ParkingRuleViolation::class);
        OperatingDay::create(1, OperatingDayMode::Scheduled, '08:00', '08:00');
    }

    public function test_weekly_schedule_requires_each_day_once(): void
    {
        $this->assertCount(7, WeeklySchedule::alwaysOpen()->days);

        $this->expectException(ParkingRuleViolation::class);
        WeeklySchedule::fromDays([
            OperatingDay::create(1, OperatingDayMode::Open24h),
            OperatingDay::create(1, OperatingDayMode::Closed),
        ]);
    }

    public function test_codes_are_normalized_and_ranges_are_bounded(): void
    {
        $this->assertSame('ENTRADA-01', ParkingCode::normalize(' entrada 01 '));
        $this->assertSame(['A-008', 'A-009', 'A-010'], SpaceCodeRange::generate('A-', 8, 10, 3));

        try {
            ParkingCode::normalize('<script>');
            $this->fail('Unsafe codes must be rejected.');
        } catch (ParkingRuleViolation $violation) {
            $this->assertSame('INVALID_CODE', $violation->errorCode);
        }

        $this->expectException(ParkingRuleViolation::class);
        SpaceCodeRange::generate('B', 1, SpaceCodeRange::MAX_SPACES + 1);
    }

    public function test_devices_only_enter_or_leave_maintenance_by_hand(): void
    {
        $this->assertSame([DeviceStatus::Maintenance], DeviceStatus::Online->manualTransitions());
        $this->assertSame([DeviceStatus::Unknown], DeviceStatus::Maintenance->manualTransitions());
        $this->assertFalse(DeviceStatus::Maintenance->canBeSetManuallyTo(DeviceStatus::Online));
    }
}
