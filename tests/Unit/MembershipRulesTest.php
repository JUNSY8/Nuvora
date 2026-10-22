<?php

namespace Tests\Unit;

use App\Modules\Memberships\Domain\AccessRules;
use App\Modules\Memberships\Domain\BillingPeriod;
use App\Modules\Memberships\Domain\MembershipStatus;
use App\Modules\Tariffs\Domain\TimeWindow;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class MembershipRulesTest extends TestCase
{
    public function test_periods_are_anchored_to_the_start_day_and_never_drift(): void
    {
        $range = fn (BillingPeriod $period, string $anchor, int $index): string => implode(' → ', array_map(
            fn (DateTimeImmutable $day): string => $day->format('Y-m-d'),
            $period->period(new DateTimeImmutable($anchor), $index),
        ));

        $this->assertSame('2026-01-31 → 2026-02-27', $range(BillingPeriod::Monthly, '2026-01-31', 0));
        $this->assertSame('2026-02-28 → 2026-03-30', $range(BillingPeriod::Monthly, '2026-01-31', 1));
        $this->assertSame('2026-03-31 → 2026-04-29', $range(BillingPeriod::Monthly, '2026-01-31', 2));
        $this->assertSame('2026-11-04 → 2026-12-03', $range(BillingPeriod::Monthly, '2026-11-04', 0));
        $this->assertSame('2026-11-04 → 2026-11-10', $range(BillingPeriod::Weekly, '2026-11-04', 0));
        $this->assertSame('2026-11-11 → 2026-11-17', $range(BillingPeriod::Weekly, '2026-11-04', 1));
        $this->assertSame('2026-11-30 → 2027-02-27', $range(BillingPeriod::Quarterly, '2026-11-30', 0));
        $this->assertSame('2028-02-29 → 2029-02-27', $range(BillingPeriod::Yearly, '2028-02-29', 0));
    }

    public function test_the_lifecycle_allows_renewing_expired_memberships_but_not_cancelled_ones(): void
    {
        $this->assertTrue(MembershipStatus::Active->canTransitionTo(MembershipStatus::Suspended));
        $this->assertTrue(MembershipStatus::Suspended->canTransitionTo(MembershipStatus::Active));
        $this->assertTrue(MembershipStatus::Expired->canTransitionTo(MembershipStatus::Active));
        $this->assertFalse(MembershipStatus::Expired->canTransitionTo(MembershipStatus::Suspended));
        $this->assertSame([], MembershipStatus::Cancelled->transitions());
        $this->assertSame(['active', 'suspended'], MembershipStatus::holdingValues());
    }

    public function test_access_rules_decide_whether_an_arrival_is_covered(): void
    {
        $rules = new AccessRules(
            lotIds: ['lot-1'],
            zoneIds: ['zone-a'],
            vehicleTypes: [VehicleType::Car],
            windows: [TimeWindow::create([1, 2, 3, 4, 5], '06:00', '20:00')],
        );
        $start = new DateTimeImmutable('2026-11-01');
        $until = new DateTimeImmutable('2026-11-30');
        // Wednesday at 09:00.
        $arrival = new DateTimeImmutable('2026-11-04 09:00');
        $code = fn (MembershipStatus $status, string $lot, VehicleType $type, DateTimeImmutable $at): string => $rules->evaluate($status, $start, $until, $lot, $type, $at)->code;

        $this->assertSame('COVERED', $code(MembershipStatus::Active, 'lot-1', VehicleType::Car, $arrival));
        $this->assertTrue($rules->evaluate(MembershipStatus::Active, $start, $until, 'lot-1', VehicleType::Car, $arrival)->covered);
        $this->assertSame('MEMBERSHIP_SUSPENDED', $code(MembershipStatus::Suspended, 'lot-1', VehicleType::Car, $arrival));
        $this->assertSame('MEMBERSHIP_INACTIVE', $code(MembershipStatus::Expired, 'lot-1', VehicleType::Car, $arrival));
        $this->assertSame('MEMBERSHIP_EXPIRED', $code(MembershipStatus::Active, 'lot-1', VehicleType::Car, new DateTimeImmutable('2026-12-01 09:00')));
        $this->assertSame('MEMBERSHIP_NOT_STARTED', $code(MembershipStatus::Active, 'lot-1', VehicleType::Car, new DateTimeImmutable('2026-10-30 09:00')));
        $this->assertSame('LOT_NOT_INCLUDED', $code(MembershipStatus::Active, 'lot-2', VehicleType::Car, $arrival));
        $this->assertSame('VEHICLE_TYPE_NOT_INCLUDED', $code(MembershipStatus::Active, 'lot-1', VehicleType::Motorcycle, $arrival));
        // Saturday, and a weekday evening: outside the plan's hours.
        $this->assertSame('OUTSIDE_ACCESS_HOURS', $code(MembershipStatus::Active, 'lot-1', VehicleType::Car, new DateTimeImmutable('2026-11-07 09:00')));
        $this->assertSame('OUTSIDE_ACCESS_HOURS', $code(MembershipStatus::Active, 'lot-1', VehicleType::Car, new DateTimeImmutable('2026-11-04 21:00')));
        // The last covered day still counts.
        $this->assertSame('COVERED', $code(MembershipStatus::Active, 'lot-1', VehicleType::Car, new DateTimeImmutable('2026-11-30 19:59')));

        $this->assertTrue($rules->allowsZone('zone-a'));
        $this->assertFalse($rules->allowsZone('zone-b'));
        $this->assertFalse($rules->allowsZone(null));
        $this->assertTrue((new AccessRules([], [], [], []))->allowsZone(null));
    }
}
