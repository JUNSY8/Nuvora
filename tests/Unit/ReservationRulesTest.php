<?php

namespace Tests\Unit;

use App\Modules\Parking\Domain\OperatingDay;
use App\Modules\Parking\Domain\OperatingDayMode;
use App\Modules\Parking\Domain\WeeklySchedule;
use App\Modules\Reservations\Domain\ReservationCapacity;
use App\Modules\Reservations\Domain\ReservationCode;
use App\Modules\Reservations\Domain\ReservationPolicy;
use App\Modules\Reservations\Domain\ReservationRuleViolation;
use App\Modules\Reservations\Domain\ReservationStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ReservationRulesTest extends TestCase
{
    public function test_the_lifecycle_only_moves_forward_except_an_undone_check_in(): void
    {
        $this->assertTrue(ReservationStatus::Pending->canTransitionTo(ReservationStatus::Confirmed));
        $this->assertTrue(ReservationStatus::Pending->canTransitionTo(ReservationStatus::Expired));
        $this->assertFalse(ReservationStatus::Pending->canTransitionTo(ReservationStatus::Active));
        $this->assertTrue(ReservationStatus::Confirmed->canTransitionTo(ReservationStatus::NoShow));
        $this->assertFalse(ReservationStatus::Confirmed->canTransitionTo(ReservationStatus::Expired));
        $this->assertTrue(ReservationStatus::Active->canTransitionTo(ReservationStatus::Confirmed));
        $this->assertFalse(ReservationStatus::Active->canTransitionTo(ReservationStatus::Cancelled));

        foreach ([ReservationStatus::Completed, ReservationStatus::Cancelled, ReservationStatus::Expired, ReservationStatus::NoShow] as $final) {
            $this->assertSame([], $final->transitions());
        }

        $this->assertSame(['pending', 'confirmed', 'active'], ReservationStatus::blockingValues());
    }

    public function test_the_policy_validates_the_window_and_freezes_derived_times(): void
    {
        $policy = ReservationPolicy::defaults();
        $now = new DateTimeImmutable('2026-11-04 10:00:00');
        $window = $policy->window(new DateTimeImmutable('2026-11-04 14:00'), new DateTimeImmutable('2026-11-04 16:00'), $now);

        $this->assertSame('13:50', $window['blocked_from']->format('H:i'));
        $this->assertSame('16:10', $window['blocked_until']->format('H:i'));
        // Arrival may be up to 15 min early even with a 10 min buffer.
        $this->assertSame('13:45', $window['arrival_from']->format('H:i'));
        $this->assertSame('14:30', $window['no_show_after']->format('H:i'));
        $this->assertSame('10:15', $policy->holdExpiresAt($now)->format('H:i'));

        $invalid = [
            'ends before start' => ['2026-11-04 16:00', '2026-11-04 14:00'],
            'in the past' => ['2026-11-04 09:00', '2026-11-04 11:00'],
            'too short' => ['2026-11-04 14:00', '2026-11-04 14:20'],
            'too long' => ['2026-11-04 14:00', '2026-11-05 14:01'],
            'too far ahead' => ['2027-02-01 14:00', '2027-02-01 16:00'],
        ];

        foreach ($invalid as $case => [$start, $end]) {
            try {
                $policy->window(new DateTimeImmutable($start), new DateTimeImmutable($end), $now);
                $this->fail("Expected {$case} to be rejected.");
            } catch (ReservationRuleViolation $violation) {
                $this->assertSame('INVALID_WINDOW', $violation->errorCode, $case);
            }
        }

        // A reservation that starts "now" tolerates a little clock skew.
        $policy->window(new DateTimeImmutable('2026-11-04 09:57'), new DateTimeImmutable('2026-11-04 11:00'), $now);

        $disabled = ReservationPolicy::create(false, 10, 15, 30, 30, 1440, 60, true);
        $this->expectExceptionObject(new ReservationRuleViolation('Este parqueadero no recibe reservas.', 'RESERVATIONS_DISABLED'));
        $disabled->window(new DateTimeImmutable('2026-11-04 14:00'), new DateTimeImmutable('2026-11-04 16:00'), $now);
    }

    public function test_policy_values_are_bounded(): void
    {
        $this->expectException(ReservationRuleViolation::class);
        ReservationPolicy::create(true, 10, 15, 30, 120, 60, 60, true);
    }

    public function test_capacity_counts_the_peak_of_overlapping_reservations(): void
    {
        $at = fn (string $time): DateTimeImmutable => new DateTimeImmutable("2026-11-04 {$time}");
        $intervals = [
            [$at('08:00'), $at('10:00')],
            [$at('10:00'), $at('12:00')],
            [$at('09:00'), $at('11:00')],
            [$at('13:00'), $at('14:00')],
        ];

        // 08-10 and 10-12 touch but never overlap: the peak is 2, not 3.
        $this->assertSame(2, ReservationCapacity::peak($intervals, $at('08:00'), $at('12:00')));
        $this->assertSame(1, ReservationCapacity::peak($intervals, $at('11:30'), $at('13:30')));
        $this->assertSame(0, ReservationCapacity::peak($intervals, $at('12:00'), $at('13:00')));
        $this->assertSame(1, ReservationCapacity::available(3, $intervals, $at('09:30'), $at('10:30')));
        $this->assertSame(0, ReservationCapacity::available(1, $intervals, $at('09:30'), $at('10:30')));
    }

    public function test_codes_are_short_unambiguous_and_searchable(): void
    {
        $code = ReservationCode::generate();

        $this->assertMatchesRegularExpression('/^R-[2-9A-HJKMNP-Z]{6}$/', $code);
        $this->assertSame('R-7K3M9Q', ReservationCode::normalize('r7k3m9q'));
        $this->assertSame('R-7K3M9Q', ReservationCode::normalize(' R-7K3M9Q '));
    }

    public function test_the_schedule_covers_intervals_including_overnight_shifts(): void
    {
        // Monday to Friday 06:00-22:00, Friday until 02:00, Sunday closed.
        $days = [];

        foreach (range(1, 7) as $day) {
            $days[] = match ($day) {
                5 => OperatingDay::create(5, OperatingDayMode::Scheduled, '06:00', '02:00'),
                6 => OperatingDay::create(6, OperatingDayMode::Open24h),
                7 => OperatingDay::create(7, OperatingDayMode::Closed),
                default => OperatingDay::create($day, OperatingDayMode::Scheduled, '06:00', '22:00'),
            };
        }

        $schedule = WeeklySchedule::fromDays($days);
        $at = fn (string $value): DateTimeImmutable => new DateTimeImmutable($value);

        $this->assertTrue($schedule->covers($at('2026-11-04 08:00'), $at('2026-11-04 21:59')));   // Wednesday
        $this->assertFalse($schedule->covers($at('2026-11-04 21:00'), $at('2026-11-04 23:00')));
        $this->assertFalse($schedule->covers($at('2026-11-04 05:00'), $at('2026-11-04 07:00')));
        // Friday night runs into Saturday, which is open all day.
        $this->assertTrue($schedule->covers($at('2026-11-06 20:00'), $at('2026-11-07 12:00')));
        // Saturday into Sunday: Sunday is closed.
        $this->assertFalse($schedule->covers($at('2026-11-07 22:00'), $at('2026-11-08 01:00')));
        $this->assertTrue(WeeklySchedule::alwaysOpen()->covers($at('2026-11-08 01:00'), $at('2026-11-10 01:00')));
    }
}
