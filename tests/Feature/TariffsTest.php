<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithParkingSessions;
use Tests\Concerns\InteractsWithTariffs;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TariffsTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithParking;
    use InteractsWithParkingSessions;
    use InteractsWithTariffs;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_tariffs_are_versioned_and_never_rewritten(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);

        $tariff = $this->actingAs($owner)
            ->postJson('/api/v1/tariffs', ['parking_lot_id' => $lot, ...$this->tariffPayload(['code' => 'carros general', 'vehicle_types' => ['motorcycle', 'car']])])
            ->assertCreated()
            ->assertJsonPath('data.tariff.code', 'CARROS-GENERAL')
            ->assertJsonPath('data.tariff.currency', 'COP')
            ->assertJsonPath('data.tariff.version', 1)
            ->assertJsonPath('data.tariff.status', 'active')
            // Vehicle types are kept in catalog order.
            ->assertJsonPath('data.tariff.vehicle_types', ['car', 'motorcycle'])
            ->json('data.tariff.id');

        $this->travel(10)->minutes();
        $changed = $this->tariffPayload(['code' => 'carros-general', 'vehicle_types' => ['car', 'motorcycle'], 'step_amount' => self::cop(3500), 'change_note' => 'Ajuste de noviembre']);

        $this->actingAs($owner)->patchJson("/api/v1/tariffs/{$tariff}", $changed)
            ->assertOk()
            ->assertJsonPath('data.tariff.version', 2)
            ->assertJsonPath('data.tariff.step_amount', self::cop(3500));

        // Saving the same configuration publishes nothing.
        $this->actingAs($owner)->patchJson("/api/v1/tariffs/{$tariff}", $changed)->assertOk()->assertJsonPath('data.tariff.version', 2);

        $this->actingAs($owner)->postJson("/api/v1/tariffs/{$tariff}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.tariff.status', 'inactive')
            ->assertJsonPath('data.tariff.version', 3);

        $versions = $this->actingAs($owner)->getJson("/api/v1/tariffs/{$tariff}/versions")->assertOk()->json('data.items');
        $this->assertSame([3, 2, 1], array_column($versions, 'version'));
        $this->assertSame(['inactive', 'active', 'active'], array_column($versions, 'status'));
        $this->assertSame([self::cop(3500), self::cop(3500), self::cop(3000)], array_column($versions, 'step_amount'));
        $this->assertSame('Ajuste de noviembre', $versions[1]['change_note']);
        $this->assertSame($owner->name, $versions[0]['author']['name']);

        $this->actingAs($owner)->getJson("/api/v1/tariffs?lot_id={$lot}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.version', 3);

        $this->assertDatabaseHas('audit_logs', ['action' => 'tariff.created', 'resource_id' => $tariff]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tariff.updated', 'resource_id' => $tariff]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tariff.status_changed', 'resource_id' => $tariff]);
        $this->assertSame(3, DB::table('tariff_versions')->where('tariff_id', $tariff)->count());

        // A duplicate code in the same lot is a field error; another lot may reuse it.
        $this->actingAs($owner)->postJson('/api/v1/tariffs', ['parking_lot_id' => $lot, ...$this->tariffPayload(['code' => 'CARROS-GENERAL'])])
            ->assertUnprocessable()->assertJsonValidationErrors(['code'], 'error.details');
        $other = $this->createLot($owner, ['code' => 'norte']);
        $this->createTariff($owner, $other, ['code' => 'CARROS-GENERAL']);
    }

    public function test_rules_are_validated_by_the_domain(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);

        $invalid = [
            ['brackets' => [['up_to_minutes' => 30, 'amount' => self::cop(3000)], ['up_to_minutes' => 15, 'amount' => self::cop(4000)]]],
            ['brackets' => [['up_to_minutes' => 15, 'amount' => self::cop(3000)], ['up_to_minutes' => 30, 'amount' => self::cop(2000)]]],
            // Pesos are charged whole: 3.000,50 is not chargeable.
            ['step_amount' => 300050],
        ];

        foreach ($invalid as $overrides) {
            $this->actingAs($owner)->postJson('/api/v1/tariffs', ['parking_lot_id' => $lot, ...$this->tariffPayload($overrides)])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'INVALID_RATES');
        }

        $this->actingAs($owner)->postJson('/api/v1/tariffs', ['parking_lot_id' => $lot, ...$this->tariffPayload(['windows' => [['days' => [8], 'start' => '19:00', 'end' => '06:00']]])])
            ->assertUnprocessable()->assertJsonValidationErrors(['windows.0.days.0'], 'error.details');
        $this->actingAs($owner)->postJson('/api/v1/tariffs', ['parking_lot_id' => $lot, ...$this->tariffPayload(['step_minutes' => 0])])
            ->assertUnprocessable()->assertJsonValidationErrors(['step_minutes'], 'error.details');

        $this->assertSame(0, DB::table('tariffs')->count());
    }

    public function test_the_exit_charges_with_the_version_in_force_at_the_entry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');
        $tariff = $this->createTariff($owner, $lot, [
            'brackets' => [['up_to_minutes' => 60, 'amount' => self::cop(5000)]],
            'step_minutes' => 60,
            'step_amount' => self::cop(3000),
        ]);

        $first = $this->enter($owner, $lot, 'TAR111')->assertCreated()->json('data.session.id');

        // The price goes up after the first car entered.
        $this->travel(10)->minutes();
        $this->actingAs($owner)->patchJson("/api/v1/tariffs/{$tariff}", $this->tariffPayload([
            'brackets' => [['up_to_minutes' => 60, 'amount' => self::cop(8000)]],
            'step_amount' => self::cop(4000),
        ]))->assertOk();
        $second = $this->enter($owner, $lot, 'TAR222')->assertCreated()->json('data.session.id');

        // 1 h 35 min for the first car: first hour $5.000 + 1 additional hour $3.000.
        $this->travel(85)->minutes();

        $quote = $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$first}/quote")
            ->assertOk()
            ->assertJsonPath('data.quote.status', 'priced')
            ->assertJsonPath('data.quote.total', self::cop(8000))
            ->assertJsonPath('data.quote.tariff.version', 1)
            ->json('data.quote');
        $this->assertNull(DB::table('parking_sessions')->where('id', $first)->value('total_amount'));

        $closed = $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$first}/exit", [], ['Idempotency-Key' => 'exit-tar111-0001'])
            ->assertOk()
            ->assertJsonPath('data.session.duration_minutes', 95)
            ->assertJsonPath('data.session.charge.status', 'priced')
            ->assertJsonPath('data.session.charge.currency', 'COP')
            ->assertJsonPath('data.session.charge.total', $quote['total'])
            ->assertJsonPath('data.session.charge.tariff.code', 'GENERAL')
            ->assertJsonPath('data.session.charge.tariff.version', 1)
            ->json('data.session.charge');
        $this->assertSame([['bracket', 'Hasta 1 hora', self::cop(5000)], ['step', '1 × 1 hora adicional', self::cop(3000)]], array_map(
            fn (array $line): array => [$line['type'], $line['label'], $line['amount']],
            $closed['lines'],
        ));

        // A retried exit returns the stored charge without pricing again.
        $this->travel(2)->hours();
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$first}/exit", [], ['Idempotency-Key' => 'exit-tar111-0001'])
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.session.charge.total', self::cop(8000));

        // The second car entered under version 2: 3 h 25 min = $8.000 + 3 × $4.000.
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$second}/exit")
            ->assertOk()
            ->assertJsonPath('data.session.charge.tariff.version', 2)
            ->assertJsonPath('data.session.charge.total', self::cop(20000));

        $this->assertDatabaseHas('parking_sessions', ['id' => $first, 'charge_status' => 'priced', 'subtotal_amount' => self::cop(8000), 'discount_amount' => 0, 'total_amount' => self::cop(8000), 'tariff_id' => $tariff]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'parking_session.closed', 'resource_id' => $first]);

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()
            ->assertJsonPath('data.charged_today', self::cop(28000))
            ->assertJsonPath('data.currency', 'COP')
            ->assertJsonPath('data.unpriced_today', 0);

        $history = $this->actingAs($owner)->getJson("/api/v1/vehicles/{$this->vehicleId('TAR111')}/history")->assertOk();
        $this->assertSame(self::cop(8000), $history->json('data.items.0.data.total_amount'));

        // Ended sessions cannot be quoted.
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$first}/quote")
            ->assertUnprocessable()->assertJsonPath('error.code', 'SESSION_NOT_OPEN');
    }

    public function test_grace_period_and_missing_tariffs_still_let_the_vehicle_leave(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner, ['grace_period_minutes' => 10]);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'M-01', ['type' => 'motorcycle']);
        $this->createTariff($owner, $lot, ['vehicle_types' => ['car']]);

        $car = $this->enter($owner, $lot, 'GRA111')->assertCreated()->json('data.session.id');
        $moto = $this->enter($owner, $lot, 'GRA22A', ['vehicle_type' => 'motorcycle'])->assertCreated()->json('data.session.id');

        $this->travel(9)->minutes();

        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$car}/exit")
            ->assertOk()
            ->assertJsonPath('data.session.charge.status', 'priced')
            ->assertJsonPath('data.session.charge.total', 0)
            ->assertJsonPath('data.session.charge.grace_applied', true)
            ->assertJsonPath('data.session.charge.lines.0.type', 'grace');

        // No tariff covers motorcycles: the exit is registered without a charge.
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$moto}/exit")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'closed')
            ->assertJsonPath('data.session.charge.status', 'unpriced')
            ->assertJsonPath('data.session.charge.total', null);

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()->assertJsonPath('data.charged_today', 0)->assertJsonPath('data.unpriced_today', 1);

        // Open and cancelled sessions carry no charge.
        $open = $this->enter($owner, $lot, 'GRA333')->assertCreated()->assertJsonPath('data.session.charge', null)->json('data.session.id');
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$open}/cancel", ['reason' => 'Placa equivocada'])
            ->assertOk()->assertJsonPath('data.session.charge', null);
    }

    public function test_the_engine_picks_the_tariff_by_vehicle_zone_and_entry_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $vip = (string) $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/zones", ['name' => 'VIP', 'code' => 'VIP', 'description' => null, 'is_active' => true, 'sort_order' => 0])
            ->assertCreated()->json('data.zone.id');
        $this->createTariff($owner, $lot);
        $this->createTariff($owner, $lot, ['code' => 'motos', 'vehicle_types' => ['motorcycle'], 'step_amount' => self::cop(1500)]);
        // Flat night: $10.000 up to 12 h for entries from 19:00 to 06:00.
        $this->createTariff($owner, $lot, [
            'code' => 'noche',
            'windows' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '19:00', 'end' => '06:00']],
            'brackets' => [['up_to_minutes' => 720, 'amount' => self::cop(10000)]],
            'step_amount' => self::cop(3000),
        ]);
        $this->createTariff($owner, $lot, ['code' => 'vip', 'zone_id' => $vip, 'step_amount' => self::cop(6000), 'daily_max_amount' => self::cop(40000)]);

        // 2 h 30 min by day: 3 started hours.
        $this->simulate($owner, $lot)->assertOk()->assertJsonPath('data.quote.tariff.code', 'GENERAL')->assertJsonPath('data.quote.total', self::cop(9000));
        $this->simulate($owner, $lot, ['vehicle_type' => 'motorcycle'])->assertOk()->assertJsonPath('data.quote.tariff.code', 'MOTOS')->assertJsonPath('data.quote.total', self::cop(4500));
        // Entering at 05:30 is still the night that started the day before.
        $this->simulate($owner, $lot, ['entry_at' => '2026-11-04T05:30', 'exit_at' => '2026-11-04T09:00'])
            ->assertOk()->assertJsonPath('data.quote.tariff.code', 'NOCHE')->assertJsonPath('data.quote.total', self::cop(10000));
        $this->simulate($owner, $lot, ['entry_at' => '2026-11-04T20:00', 'exit_at' => '2026-11-05T10:00'])
            ->assertOk()->assertJsonPath('data.quote.total', self::cop(16000));
        // The zone is the narrowest filter, and the daily maximum caps each 24 h.
        $this->simulate($owner, $lot, ['zone_id' => $vip, 'entry_at' => '2026-11-04T20:00', 'exit_at' => '2026-11-06T02:00'])
            ->assertOk()
            ->assertJsonPath('data.quote.tariff.code', 'VIP')
            ->assertJsonPath('data.quote.total', self::cop(76000))
            ->assertJsonPath('data.quote.lines.0.type', 'day');

        // A real session takes the zone of its space.
        $space = $this->createSpace($owner, $lot, 'V-01', ['zone_id' => $vip]);
        $session = $this->enter($owner, $lot, 'VIP123', ['space_id' => $space])->assertCreated()->json('data.session.id');
        $this->travel(30)->minutes();
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}/quote")->assertOk()->assertJsonPath('data.quote.tariff.code', 'VIP');

        // A past stay shows what was in force then: nothing, before the tariffs existed.
        $this->simulate($owner, $lot, ['entry_at' => '2026-10-01T10:00', 'exit_at' => '2026-10-01T11:00'])
            ->assertOk()->assertJsonPath('data.quote.status', 'unpriced')->assertJsonPath('data.quote.total', null);
        // A stay that entered before the tariffs and left after uses those in force at the exit.
        $this->simulate($owner, $lot, ['entry_at' => '2026-10-31T10:00', 'exit_at' => '2026-11-01T09:00'])
            ->assertOk()->assertJsonPath('data.quote.tariff.code', 'GENERAL');
        $this->simulate($owner, $lot, ['entry_at' => '2026-11-04T12:00', 'exit_at' => '2026-11-04T11:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['exit_at'], 'error.details');
    }

    public function test_discounts_need_their_permission_and_promotions_apply_alone(): void
    {
        // Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createTariff($owner, $lot);
        $agreement = $this->createDiscount($owner, ['code' => 'cine', 'name' => 'Convenio cine', 'type' => 'free_minutes', 'value' => 120]);
        $promotion = $this->createDiscount($owner, ['code' => 'martes', 'name' => 'Martes 10 %', 'type' => 'percentage', 'value' => 1000, 'application' => 'automatic', 'days' => [2]]);
        $voucher = $this->createDiscount($owner, ['code' => 'bono', 'name' => 'Bono', 'type' => 'fixed_amount', 'value' => self::cop(2000), 'parking_lot_id' => $lot]);
        $this->assertDatabaseHas('discounts', ['id' => $voucher, 'currency' => 'COP']);

        $operator = User::factory()->create();
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $this->addMember($owner, $tenant, $cashier, 'cashier');

        $a = $this->enter($owner, $lot, 'DES111', ['auto_assign' => false])->assertCreated()->json('data.session.id');
        $b = $this->enter($owner, $lot, 'DES222', ['auto_assign' => false])->assertCreated()->json('data.session.id');
        $c = $this->enter($owner, $lot, 'DES333', ['auto_assign' => false])->assertCreated()->json('data.session.id');
        $this->travel(170)->minutes();

        // Without a choice, the Tuesday promotion applies: 3 h = $9.000 - 10 %.
        $this->actingAs($operator)->getJson("/api/v1/parking-sessions/{$a}/quote")
            ->assertOk()->assertJsonPath('data.quote.discount_rule.code', 'MARTES')->assertJsonPath('data.quote.total', self::cop(8100));
        $this->actingAs($operator)->getJson("/api/v1/parking-sessions/{$a}/quote?discount_id={$agreement}")->assertForbidden();
        $this->actingAs($operator)->postJson("/api/v1/parking-sessions/{$a}/exit", ['discount_id' => $agreement])->assertForbidden();
        $this->assertSame('open', DB::table('parking_sessions')->where('id', $a)->value('status'));

        // The cashier's choice replaces the promotion: two free hours leave one.
        $this->actingAs($cashier)->postJson("/api/v1/parking-sessions/{$a}/exit", ['discount_id' => $agreement])
            ->assertOk()
            ->assertJsonPath('data.session.charge.subtotal', self::cop(9000))
            ->assertJsonPath('data.session.charge.discount', self::cop(6000))
            ->assertJsonPath('data.session.charge.total', self::cop(3000))
            ->assertJsonPath('data.session.charge.charged_minutes', 50)
            ->assertJsonPath('data.session.charge.discount_rule.code', 'CINE');
        $this->assertDatabaseHas('parking_sessions', ['id' => $a, 'discount_id' => $agreement]);

        $this->actingAs($operator)->postJson("/api/v1/parking-sessions/{$b}/exit")
            ->assertOk()->assertJsonPath('data.session.charge.discount_rule.code', 'MARTES')->assertJsonPath('data.session.charge.total', self::cop(8100));

        // Promotions are not chosen by hand, and inactive discounts are refused.
        $this->actingAs($cashier)->postJson("/api/v1/parking-sessions/{$c}/exit", ['discount_id' => $promotion])
            ->assertUnprocessable()->assertJsonPath('error.code', 'DISCOUNT_NOT_APPLICABLE');
        $this->actingAs($owner)->postJson("/api/v1/discounts/{$voucher}/status", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.discount.status', 'inactive');
        $this->actingAs($cashier)->postJson("/api/v1/parking-sessions/{$c}/exit", ['discount_id' => $voucher])
            ->assertUnprocessable()->assertJsonPath('error.code', 'DISCOUNT_NOT_APPLICABLE');
        $this->assertSame('open', DB::table('parking_sessions')->where('id', $c)->value('status'));

        $this->actingAs($cashier)->getJson("/api/v1/discounts?lot_id={$lot}&application=manual&status=active")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.code', 'CINE');

        // A fixed amount for every lot must name its currency.
        $this->actingAs($owner)->postJson('/api/v1/discounts', [
            'code' => 'sin-moneda', 'name' => 'Sin moneda', 'type' => 'fixed_amount', 'value' => 100, 'application' => 'manual', 'vehicle_types' => [], 'days' => [],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_DISCOUNT');
    }

    public function test_roles_and_tenants_limit_tariffs(): void
    {
        [$owner, $tenant] = $this->createTenantOwner('Empresa A');
        [$intruder] = $this->createTenantOwner('Empresa B');
        $lot = $this->createLot($owner);
        $zone = (string) $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/zones", ['name' => 'Norte', 'code' => 'N', 'description' => null, 'is_active' => true, 'sort_order' => 0])
            ->assertCreated()->json('data.zone.id');
        $tariff = $this->createTariff($owner, $lot);
        $discount = $this->createDiscount($owner);
        $otherLot = $this->createLot($intruder, ['code' => 'otra']);

        $operator = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');

        $this->actingAs($operator)->getJson("/api/v1/tariffs?lot_id={$lot}")->assertOk()->assertJsonCount(1, 'data.items');
        $this->simulate($operator, $lot)->assertOk();
        $this->actingAs($operator)->postJson('/api/v1/tariffs', ['parking_lot_id' => $lot, ...$this->tariffPayload(['code' => 'otra'])])->assertForbidden();
        $this->actingAs($operator)->patchJson("/api/v1/tariffs/{$tariff}", $this->tariffPayload())->assertForbidden();
        $this->actingAs($operator)->postJson('/api/v1/discounts', ['code' => 'x'])->assertForbidden();

        $this->actingAs($intruder)->getJson("/api/v1/tariffs?lot_id={$lot}")->assertUnprocessable()->assertJsonValidationErrors(['lot_id'], 'error.details');
        $this->actingAs($intruder)->patchJson("/api/v1/tariffs/{$tariff}", $this->tariffPayload())->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/tariffs/{$tariff}/status", ['status' => 'inactive'])->assertNotFound();
        $this->actingAs($intruder)->getJson("/api/v1/tariffs/{$tariff}/versions")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/discounts/{$discount}/status", ['status' => 'inactive'])->assertNotFound();
        $this->actingAs($intruder)->getJson('/api/v1/discounts')->assertOk()->assertJsonCount(0, 'data.items');
        $this->simulate($intruder, $lot)->assertUnprocessable()->assertJsonValidationErrors(['lot_id'], 'error.details');

        // Another tenant's lot, zone or discount in a body is a validation answer, never a write.
        $this->actingAs($intruder)->postJson('/api/v1/tariffs', ['parking_lot_id' => $lot, ...$this->tariffPayload()])
            ->assertUnprocessable()->assertJsonValidationErrors(['parking_lot_id'], 'error.details');
        $this->actingAs($intruder)->postJson('/api/v1/tariffs', ['parking_lot_id' => $otherLot, ...$this->tariffPayload(['zone_id' => $zone])])
            ->assertUnprocessable()->assertJsonValidationErrors(['zone_id'], 'error.details');
        $this->createTariff($intruder, $otherLot);
        $this->createSpace($intruder, $otherLot, 'B-01');
        $session = $this->enter($intruder, $otherLot, 'INT123')->assertCreated()->json('data.session.id');
        $this->actingAs($intruder)->postJson("/api/v1/parking-sessions/{$session}/exit", ['discount_id' => $discount])
            ->assertUnprocessable()->assertJsonValidationErrors(['discount_id'], 'error.details');

        $this->assertSame(1, DB::table('tariffs')->where('parking_lot_id', $lot)->count());
        $this->assertSame('active', DB::table('tariffs')->where('id', $tariff)->value('status'));
    }

    public function test_tariffs_protect_the_lot_currency_and_their_zone(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $empty = $this->createLot($owner, ['code' => 'vacio']);
        $zone = (string) $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/zones", ['name' => 'Sur', 'code' => 'S', 'description' => null, 'is_active' => true, 'sort_order' => 0])
            ->assertCreated()->json('data.zone.id');
        $this->createTariff($owner, $lot, ['zone_id' => $zone]);

        $this->actingAs($owner)->patchJson("/api/v1/parking-lots/{$lot}", $this->lotPayload(['currency' => 'USD']))
            ->assertUnprocessable()->assertJsonPath('error.code', 'CURRENCY_IN_USE');
        $this->assertSame('COP', DB::table('parking_lots')->where('id', $lot)->value('currency'));

        // Other changes and lots without tariffs are not affected.
        $this->actingAs($owner)->patchJson("/api/v1/parking-lots/{$lot}", $this->lotPayload(['name' => 'Sede Centro 2']))->assertOk();
        $this->actingAs($owner)->patchJson("/api/v1/parking-lots/{$empty}", $this->lotPayload(['code' => 'vacio', 'currency' => 'USD']))->assertOk();

        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}/zones/{$zone}")
            ->assertUnprocessable()->assertJsonPath('error.code', 'ZONE_IN_USE');
    }

    public function test_the_database_rejects_an_inconsistent_charge(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The charge CHECK constraint exists only on PostgreSQL.');
        }

        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createTariff($owner, $lot);
        $session = $this->enter($owner, $lot, 'CHK123', ['auto_assign' => false])->assertCreated()->json('data.session.id');
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/exit")->assertOk();

        $this->expectException(QueryException::class);

        // A total that does not match subtotal minus discount.
        DB::table('parking_sessions')->where('id', $session)->update(['total_amount' => 1]);
    }

    private function vehicleId(string $plate): string
    {
        return (string) DB::table('vehicles')->where('plate', $plate)->value('id');
    }
}
