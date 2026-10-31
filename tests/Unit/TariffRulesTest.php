<?php

namespace Tests\Unit;

use App\Modules\Shared\Domain\Currency;
use App\Modules\Tariffs\Domain\DiscountApplication;
use App\Modules\Tariffs\Domain\DiscountRule;
use App\Modules\Tariffs\Domain\DiscountType;
use App\Modules\Tariffs\Domain\PriceCalculator;
use App\Modules\Tariffs\Domain\PriceLine;
use App\Modules\Tariffs\Domain\RateSchedule;
use App\Modules\Tariffs\Domain\TariffDefinition;
use App\Modules\Tariffs\Domain\TariffRuleViolation;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Domain\TimeWindow;
use App\Modules\Vehicles\Domain\VehicleType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class TariffRulesTest extends TestCase
{
    /** Pesos to COP minor units. */
    private static function cop(int $pesos): int
    {
        return $pesos * 100;
    }

    public function test_hourly_and_fractional_rates_charge_every_started_step(): void
    {
        $hourly = RateSchedule::create([], 60, self::cop(3000), null, 'COP');

        $this->assertSame(0, $hourly->charge(0)['amount']);
        $this->assertSame(self::cop(3000), $hourly->charge(1)['amount']);
        $this->assertSame(self::cop(3000), $hourly->charge(60)['amount']);
        $this->assertSame(self::cop(6000), $hourly->charge(61)['amount']);
        $this->assertSame('2 × 1 hora', $hourly->charge(61)['lines'][0]->label);

        $fraction = RateSchedule::create([], 15, self::cop(1000), null, 'COP');

        $this->assertSame(self::cop(7000), $fraction->charge(95)['amount']);
        $this->assertSame(7, $fraction->charge(95)['lines'][0]->quantity);
    }

    public function test_brackets_are_cumulative_and_steps_continue_after_the_last(): void
    {
        // 00-15 $2.000, 16-30 $3.000, 31-60 $5.000, then $4.000 per additional hour.
        $rates = RateSchedule::create([
            ['up_to_minutes' => 15, 'amount' => self::cop(2000)],
            ['up_to_minutes' => 30, 'amount' => self::cop(3000)],
            ['up_to_minutes' => 60, 'amount' => self::cop(5000)],
        ], 60, self::cop(4000), null, 'COP');

        $this->assertSame(self::cop(2000), $rates->charge(15)['amount']);
        $this->assertSame(self::cop(3000), $rates->charge(16)['amount']);
        $this->assertSame(self::cop(5000), $rates->charge(60)['amount']);
        $this->assertSame(self::cop(9000), $rates->charge(61)['amount']);
        $this->assertSame(self::cop(13000), $rates->charge(150)['amount']);

        $lines = array_map(fn (PriceLine $line): array => [$line->type, $line->label, $line->amount], $rates->charge(150)['lines']);
        $this->assertSame([['bracket', 'Hasta 1 hora', self::cop(5000)], ['step', '2 × 1 hora adicional', self::cop(8000)]], $lines);

        // Progressive: first hour $5.000, second $4.000, each additional $3.000.
        $progressive = RateSchedule::create([
            ['up_to_minutes' => 60, 'amount' => self::cop(5000)],
            ['up_to_minutes' => 120, 'amount' => self::cop(9000)],
        ], 60, self::cop(3000), null, 'COP');

        $this->assertSame(self::cop(9000), $progressive->charge(100)['amount']);
        $this->assertSame(self::cop(15000), $progressive->charge(230)['amount']);
    }

    public function test_the_daily_maximum_caps_each_24_hours_from_the_entry(): void
    {
        $rates = RateSchedule::create([], 60, self::cop(3000), self::cop(25000), 'COP');

        // 5 h cost $15.000, below the cap.
        $this->assertSame(self::cop(15000), $rates->charge(300)['amount']);
        // 10 h would be $30.000: capped.
        $this->assertSame(self::cop(25000), $rates->charge(600)['amount']);
        $this->assertSame('daily_cap', $rates->charge(600)['lines'][0]->type);
        // 30 h: one full day capped plus 6 h.
        $result = $rates->charge(1800);
        $this->assertSame(self::cop(43000), $result['amount']);
        $this->assertSame(['day', 'step'], array_map(fn (PriceLine $line): string => $line->type, $result['lines']));
        $this->assertSame(self::cop(50000), $rates->charge(2 * 1440)['amount']);

        // A flat daily rate is a step of one day.
        $daily = RateSchedule::create([], 1440, self::cop(20000), null, 'COP');
        $this->assertSame(self::cop(40000), $daily->charge(1441)['amount']);
        $this->assertSame('2 × 1 día', $daily->charge(1441)['lines'][0]->label);
    }

    public function test_rates_reject_inconsistent_configurations(): void
    {
        $invalid = [
            'decreasing minutes' => fn () => RateSchedule::create([['up_to_minutes' => 30, 'amount' => 100], ['up_to_minutes' => 15, 'amount' => 200]], 60, 100, null, 'USD'),
            'cheaper longer stay' => fn () => RateSchedule::create([['up_to_minutes' => 15, 'amount' => 300], ['up_to_minutes' => 30, 'amount' => 200]], 60, 100, null, 'USD'),
            'zero step' => fn () => RateSchedule::create([], 0, 100, null, 'USD'),
            'negative amount' => fn () => RateSchedule::create([], 60, -1, null, 'USD'),
            'zero cap' => fn () => RateSchedule::create([], 60, 100, 0, 'USD'),
            'peso cents' => fn () => RateSchedule::create([], 60, 350050, null, 'COP'),
            'malformed bracket' => fn () => RateSchedule::create([['up_to_minutes' => '15', 'amount' => 100]], 60, 100, null, 'USD'),
        ];

        foreach ($invalid as $case => $build) {
            try {
                $build();
                $this->fail("Expected a rule violation for {$case}.");
            } catch (TariffRuleViolation $violation) {
                $this->assertSame('INVALID_RATES', $violation->errorCode, $case);
            }
        }

        $this->assertSame(['brackets' => [], 'step_minutes' => 60, 'step_amount' => 150, 'daily_max_amount' => null], RateSchedule::create([], 60, 150, null, 'USD')->toArray());
        $this->assertTrue(Currency::isChargeable(500000, 'COP'));
        $this->assertSame(2, Currency::exponent('cop'));
    }

    public function test_time_windows_follow_local_days_and_cross_midnight(): void
    {
        // Monday to Friday nights, 19:00 to 06:00.
        $night = TimeWindow::create([5, 1, 2, 3, 4, 4], '19:00', '06:00');
        $this->assertSame([1, 2, 3, 4, 5], $night->days);

        $this->assertTrue($night->contains(new DateTimeImmutable('2026-11-06 19:00')));   // Friday
        $this->assertTrue($night->contains(new DateTimeImmutable('2026-11-07 05:59')));   // Saturday early: Friday's night
        $this->assertFalse($night->contains(new DateTimeImmutable('2026-11-07 19:30')));  // Saturday night
        $this->assertFalse($night->contains(new DateTimeImmutable('2026-11-09 05:00')));  // Monday early: Sunday's night
        $this->assertTrue($night->contains(new DateTimeImmutable('2026-11-10 05:00')));   // Tuesday early: Monday's night
        $this->assertFalse($night->contains(new DateTimeImmutable('2026-11-10 06:00')));

        $weekend = TimeWindow::create([6, 7], '00:00', '00:00');
        $this->assertTrue($weekend->contains(new DateTimeImmutable('2026-11-08 23:59')));
        $this->assertFalse($weekend->contains(new DateTimeImmutable('2026-11-09 00:00')));

        $this->expectException(TariffRuleViolation::class);
        TimeWindow::create([1], '25:00', '06:00');
    }

    public function test_the_highest_priority_then_the_most_specific_tariff_prices_the_stay(): void
    {
        $rates = RateSchedule::create([], 60, 100, null, 'USD');
        $general = $this->tariff('general', $rates);
        $motos = $this->tariff('motos', $rates, vehicleTypes: [VehicleType::Motorcycle]);
        $night = $this->tariff('noche', $rates, windows: [TimeWindow::create([1, 2, 3, 4, 5, 6, 7], '19:00', '06:00')]);
        $vip = $this->tariff('vip', $rates, zoneId: 'zone-vip');
        $inactive = $this->tariff('vieja', $rates, priority: 99, status: TariffStatus::Inactive);
        $all = [$general, $motos, $night, $vip, $inactive];
        $day = new DateTimeImmutable('2026-11-04 10:00');
        $evening = new DateTimeImmutable('2026-11-04 21:00');

        $this->assertSame('general', TariffDefinition::select($all, VehicleType::Car, null, $day)?->code);
        $this->assertSame('motos', TariffDefinition::select($all, VehicleType::Motorcycle, null, $day)?->code);
        // A window outranks a vehicle type; a zone outranks both.
        $this->assertSame('noche', TariffDefinition::select($all, VehicleType::Motorcycle, null, $evening)?->code);
        $this->assertSame('vip', TariffDefinition::select($all, VehicleType::Motorcycle, 'zone-vip', $evening)?->code);

        // Priority comes first.
        $promoted = $this->tariff('general-alta', $rates, priority: 10);
        $this->assertSame('general-alta', TariffDefinition::select([...$all, $promoted], VehicleType::Car, 'zone-vip', $evening)?->code);

        // Equal rank: the code decides, whatever the order received.
        $twin = $this->tariff('aaa', $rates);
        $this->assertSame('aaa', TariffDefinition::select([$general, $twin], VehicleType::Car, null, $day)?->code);
        $this->assertSame('aaa', TariffDefinition::select([$twin, $general], VehicleType::Car, null, $day)?->code);

        $this->assertNull(TariffDefinition::select([$motos, $vip], VehicleType::Car, null, $day));
    }

    public function test_grace_period_makes_short_stays_free_and_tariffs_can_override_it(): void
    {
        $tariff = $this->tariff('general', RateSchedule::create([], 60, self::cop(3000), null, 'COP'));

        $free = PriceCalculator::calculate($tariff, 15, 15, 'COP');
        $this->assertTrue($free->graceApplied);
        $this->assertSame(0, $free->total);
        $this->assertSame('grace', $free->lines[0]->type);

        $charged = PriceCalculator::calculate($tariff, 16, 15, 'COP');
        $this->assertFalse($charged->graceApplied);
        $this->assertSame(self::cop(3000), $charged->total);
        $this->assertSame(16, $charged->chargedMinutes);

        $strict = $this->tariff('estricta', RateSchedule::create([], 60, self::cop(3000), null, 'COP'), graceMinutes: 0);
        $this->assertSame(self::cop(3000), PriceCalculator::calculate($strict, 5, 15, 'COP')->total);
    }

    public function test_discounts_do_not_stack_and_round_to_the_currency_step(): void
    {
        $tariff = $this->tariff('general', RateSchedule::create([], 60, self::cop(3350), null, 'COP'));
        $tenPercent = $this->discount('diez', DiscountType::Percentage, 1000, DiscountApplication::Automatic);
        $twoHours = $this->discount('dos-horas', DiscountType::FreeMinutes, 120, DiscountApplication::Automatic);
        $fixed = $this->discount('bono', DiscountType::FixedAmount, self::cop(50000), DiscountApplication::Manual, 'COP');

        // 3 h = $10.050; 10 % is $1.005, rounded to whole pesos.
        $percent = PriceCalculator::calculate($tariff, 180, 0, 'COP', null, [$tenPercent]);
        $this->assertSame(self::cop(10050), $percent->subtotal);
        $this->assertSame(self::cop(1005), $percent->discount);
        $this->assertSame(self::cop(9045), $percent->total);
        $this->assertSame('diez (10 %)', $percent->lines[1]->label);
        $this->assertSame(-self::cop(1005), $percent->lines[1]->amount);

        // The best promotion wins: two free hours save $6.700.
        $best = PriceCalculator::calculate($tariff, 180, 0, 'COP', null, [$tenPercent, $twoHours]);
        $this->assertSame('dos-horas', $best->discountRule?->code);
        $this->assertSame(self::cop(3350), $best->total);
        $this->assertSame(60, $best->chargedMinutes);

        // The cashier's choice replaces promotions, and never goes below zero.
        $manual = PriceCalculator::calculate($tariff, 180, 0, 'COP', $fixed, [$twoHours]);
        $this->assertSame('bono', $manual->discountRule?->code);
        $this->assertSame(0, $manual->total);
        $this->assertSame(self::cop(10050), $manual->discount);

        // Within grace nothing is discounted.
        $this->assertNull(PriceCalculator::calculate($tariff, 10, 15, 'COP', $fixed)->discountRule);
    }

    public function test_discount_availability_follows_lot_vehicle_currency_and_local_dates(): void
    {
        $discount = new DiscountRule(
            id: 'd1',
            code: 'martes',
            name: 'Martes de motos',
            type: DiscountType::FixedAmount,
            value: 100,
            currency: 'USD',
            application: DiscountApplication::Automatic,
            status: TariffStatus::Active,
            lotId: 'lot-1',
            vehicleTypes: [VehicleType::Motorcycle],
            days: [2],
            validFrom: '2026-11-01',
            validUntil: '2026-11-30',
        );
        $tuesday = new DateTimeImmutable('2026-11-10 12:00');

        $this->assertNull($discount->unavailableReason('lot-1', 'USD', VehicleType::Motorcycle, $tuesday));
        $this->assertNotNull($discount->unavailableReason('lot-2', 'USD', VehicleType::Motorcycle, $tuesday));
        $this->assertNotNull($discount->unavailableReason('lot-1', 'COP', VehicleType::Motorcycle, $tuesday));
        $this->assertNotNull($discount->unavailableReason('lot-1', 'USD', VehicleType::Car, $tuesday));
        $this->assertNotNull($discount->unavailableReason('lot-1', 'USD', VehicleType::Motorcycle, new DateTimeImmutable('2026-11-11 12:00')));
        $this->assertSame('El descuento ya venció.', $discount->unavailableReason('lot-1', 'USD', VehicleType::Motorcycle, new DateTimeImmutable('2026-12-01 12:00')));

        foreach ([[DiscountType::Percentage, 0, null], [DiscountType::Percentage, 10001, null], [DiscountType::FreeMinutes, 0, null], [DiscountType::FixedAmount, 100, null], [DiscountType::FixedAmount, 150, 'COP']] as [$type, $value, $currency]) {
            try {
                DiscountRule::assertValue($type, $value, $currency);
                $this->fail("Expected {$type->value} {$value} to be rejected.");
            } catch (TariffRuleViolation $violation) {
                $this->assertSame('INVALID_DISCOUNT', $violation->errorCode);
            }
        }
    }

    /**
     * @param  list<VehicleType>  $vehicleTypes
     * @param  list<TimeWindow>  $windows
     */
    private function tariff(
        string $code,
        RateSchedule $rates,
        int $priority = 0,
        array $vehicleTypes = [],
        ?string $zoneId = null,
        array $windows = [],
        ?int $graceMinutes = null,
        TariffStatus $status = TariffStatus::Active,
    ): TariffDefinition {
        return new TariffDefinition("t-{$code}", "v-{$code}", 1, $code, ucfirst($code), $status, $priority, $vehicleTypes, $zoneId, $windows, $graceMinutes, $rates);
    }

    private function discount(string $code, DiscountType $type, int $value, DiscountApplication $application, ?string $currency = null): DiscountRule
    {
        return new DiscountRule("d-{$code}", $code, $code, $type, $value, $currency, $application, TariffStatus::Active, null, [], [], null, null);
    }
}
