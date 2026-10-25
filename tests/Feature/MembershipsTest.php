<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithMemberships;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithParkingSessions;
use Tests\Concerns\InteractsWithTariffs;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class MembershipsTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithMemberships;
    use InteractsWithParking;
    use InteractsWithParkingSessions;
    use InteractsWithTariffs;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_plans_are_validated_and_count_what_they_sold(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner();
        [$other] = $this->createTenantOwner('Otra empresa');
        $lot = $this->createLot($owner);
        $foreignLot = $this->createLot($other, ['code' => 'ajeno']);

        $plan = $this->actingAs($owner)->postJson('/api/v1/membership-plans', $this->planPayload([
            'code' => 'vip noche',
            'category' => 'vip',
            'parking_lot_ids' => [$lot],
            'vehicle_types' => ['motorcycle', 'car'],
            'windows' => [['days' => [5, 1], 'start' => '19:00', 'end' => '06:00']],
            'capacity' => 1,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.plan.code', 'VIP-NOCHE')
            ->assertJsonPath('data.plan.vehicle_types', ['car', 'motorcycle'])
            ->assertJsonPath('data.plan.windows.0.days', [1, 5])
            ->json('data.plan.id');

        $this->actingAs($owner)->postJson('/api/v1/membership-plans', $this->planPayload(['code' => 'VIP-NOCHE']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code'], 'error.details');
        $this->actingAs($owner)->postJson('/api/v1/membership-plans', $this->planPayload(['code' => 'cents', 'price_amount' => 12000050]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_PLAN');
        $this->actingAs($owner)->postJson('/api/v1/membership-plans', $this->planPayload(['code' => 'ajeno', 'parking_lot_ids' => [$foreignLot]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['parking_lot_ids'], 'error.details');

        $customer = $this->createCustomer($owner);
        $this->sell($owner, $plan, $customer, ['VIP001'])->assertCreated();
        // The plan has one place only.
        $this->sell($owner, $plan, $this->createCustomer($owner, ['name' => 'Otro']), ['VIP002'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PLAN_FULL');

        $this->actingAs($owner)->getJson('/api/v1/membership-plans')->assertOk()->assertJsonPath('data.items.0.sold', 1);

        $this->actingAs($owner)->postJson("/api/v1/membership-plans/{$plan}/status", ['status' => 'inactive'])->assertOk();
        $this->sell($owner, $plan, $customer, ['VIP003'])->assertUnprocessable()->assertJsonPath('error.code', 'PLAN_INACTIVE');

        $operator = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $this->actingAs($operator)->getJson('/api/v1/membership-plans')->assertOk();
        $this->actingAs($operator)->postJson('/api/v1/membership-plans', $this->planPayload(['code' => 'otro']))->assertForbidden();
        $this->actingAs($other)->patchJson("/api/v1/membership-plans/{$plan}", $this->planPayload())->assertNotFound();
    }

    public function test_selling_records_the_first_period_and_keeps_each_vehicle_in_one_membership(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $assignable = $this->createSpace($owner, $lot, 'M-01', ['is_assignable' => true]);
        $plain = $this->createSpace($owner, $lot, 'A-01');
        $company = $this->createCustomer($owner, ['type' => 'business', 'name' => 'Transportes Andinos']);
        $corporate = $this->createPlan($owner, ['code' => 'corp', 'category' => 'corporate', 'max_vehicles' => 3]);

        $membership = $this->sell($owner, $corporate, $company, ['EMP001', 'emp-002'], ['assigned_lot_id' => $lot, 'assigned_space_id' => $assignable])
            ->assertCreated()
            ->assertJsonPath('data.membership.status', 'active')
            ->assertJsonPath('data.membership.customer.name', 'Transportes Andinos')
            ->assertJsonPath('data.membership.current_period_start', '2026-11-01')
            ->assertJsonPath('data.membership.current_period_end', '2026-11-30')
            ->assertJsonPath('data.membership.covered_until', '2026-12-03')
            ->assertJsonPath('data.membership.price_amount', 12000000)
            ->assertJsonPath('data.membership.assigned_space.code', 'M-01')
            ->assertJsonPath('data.membership.periods.0.status', 'due')
            ->assertJsonPath('data.membership.periods.0.origin', 'subscription')
            ->assertJsonPath('data.membership.vehicles.0.country', 'CO')
            ->assertJsonPath('data.membership.vehicles.0.type', 'car')
            ->assertJsonCount(2, 'data.membership.vehicles')
            ->json('data.membership');
        $this->assertMatchesRegularExpression('/^M-[2-9A-Z]{6}$/', $membership['code']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.created', 'resource_id' => $membership['id']]);

        $person = $this->createCustomer($owner, ['name' => 'Ana Torres']);
        $single = $this->createPlan($owner);
        $this->sell($owner, $single, $person, ['EMP001'])->assertStatus(409)->assertJsonPath('error.code', 'VEHICLE_ALREADY_MEMBER');
        $this->sell($owner, $single, $person, ['ONE001', 'ONE002'])->assertUnprocessable()->assertJsonPath('error.code', 'TOO_MANY_VEHICLES');
        $this->sell($owner, $single, $person, ['ONE001'], ['assigned_lot_id' => $lot, 'assigned_space_id' => $plain])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_NOT_ASSIGNABLE');
        $this->sell($owner, $single, $person, ['ONE001'], ['assigned_lot_id' => $lot, 'assigned_space_id' => $assignable])
            ->assertStatus(409)->assertJsonPath('error.code', 'SPACE_ALREADY_ASSIGNED');
        $this->assertSame(0, DB::table('vehicles')->where('plate', 'ONE001')->count());

        // Vehicles join and leave; the last one cannot leave a membership in force.
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership['id']}/vehicles", ['plate' => 'EMP003', 'country' => 'CO', 'vehicle_type' => 'car'])
            ->assertOk()->assertJsonCount(3, 'data.membership.vehicles');
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership['id']}/vehicles", ['plate' => 'EMP004', 'country' => 'CO', 'vehicle_type' => 'car'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'TOO_MANY_VEHICLES');
        $first = $membership['vehicles'][0]['id'];
        $this->actingAs($owner)->deleteJson("/api/v1/memberships/{$membership['id']}/vehicles/{$first}")->assertOk()->assertJsonCount(2, 'data.membership.vehicles');

        // Once it left, the vehicle can join another membership.
        $this->sell($owner, $single, $person, ['EMP001'])->assertCreated();

        $history = $this->actingAs($owner)->getJson("/api/v1/vehicles/{$first}/history")->assertOk()->json('data.items');
        $this->assertSame(['membership_added', 'membership_removed', 'membership_added', 'registered'], array_column($history, 'type'));
    }

    public function test_a_member_parks_covered_in_the_assigned_space(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $other = $this->createLot($owner, ['code' => 'norte']);
        $this->createSpace($owner, $lot, 'A-01');
        $assigned = $this->createSpace($owner, $lot, 'M-01', ['is_assignable' => true]);
        $this->createSpace($owner, $other, 'N-01');
        $this->createTariff($owner, $lot);
        $this->createTariff($owner, $other);
        $this->createDiscount($owner, ['code' => 'cine', 'type' => 'free_minutes', 'value' => 60]);
        $plan = $this->createPlan($owner, ['parking_lot_ids' => [$lot]]);
        $customer = $this->createCustomer($owner);
        $membership = $this->sell($owner, $plan, $customer, ['MEM123'], ['assigned_lot_id' => $lot, 'assigned_space_id' => $assigned])
            ->assertCreated()->json('data.membership');

        $session = $this->enter($owner, $lot, 'MEM123')
            ->assertCreated()
            ->assertJsonPath('data.session.space.code', 'M-01')
            ->assertJsonPath('data.session.membership.code', $membership['code'])
            ->assertJsonPath('data.session.customer.id', $customer)
            ->assertJsonPath('data.notices.0.type', 'info')
            ->json('data.session.id');

        // 2 h 10 min: the tariff would charge 3 hours, the membership pays them.
        $this->travel(130)->minutes();
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}/quote")
            ->assertOk()
            ->assertJsonPath('data.quote.status', 'covered')
            ->assertJsonPath('data.quote.total', 0)
            ->assertJsonPath('data.quote.subtotal', self::cop(9000))
            ->assertJsonPath('data.quote.membership.code', $membership['code']);

        $discount = DB::table('discounts')->value('id');
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/exit", ['discount_id' => $discount])
            ->assertOk()
            ->assertJsonPath('data.session.charge.status', 'covered')
            ->assertJsonPath('data.session.charge.total', 0)
            ->assertJsonPath('data.session.charge.subtotal', self::cop(9000))
            ->assertJsonPath('data.session.charge.discount', self::cop(9000))
            ->assertJsonPath('data.session.charge.discount_rule', null)
            ->assertJsonPath('data.session.charge.lines.1.type', 'membership');
        $this->assertDatabaseHas('parking_sessions', ['id' => $session, 'charge_status' => 'covered', 'total_amount' => 0, 'membership_id' => $membership['id']]);

        // The plan does not include the north lot: the member pays as a visitor there.
        $this->enter($owner, $other, 'MEM123')
            ->assertCreated()
            ->assertJsonPath('data.session.membership', null)
            ->assertJsonPath('data.notices.0.type', 'warning');

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()->assertJsonPath('data.charged_today', 0)->assertJsonPath('data.exits_today', 1)->assertJsonPath('data.covered_today', 1);

        $exited = DB::table('vehicle_events')->where('type', 'exited')->value('data');
        $this->assertSame('covered', json_decode((string) $exited, true)['charge_status']);
    }

    public function test_suspension_cancellation_and_renewal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');
        $plan = $this->createPlan($owner);
        $membership = $this->sell($owner, $plan, $this->createCustomer($owner), ['SUS123'])->assertCreated()->json('data.membership.id');
        $this->payPeriods($owner, $membership);

        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership}/suspend", ['reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership}/suspend", ['reason' => 'Pago pendiente'])
            ->assertOk()->assertJsonPath('data.membership.status', 'suspended')->assertJsonPath('data.membership.status_reason', 'Pago pendiente');
        $session = $this->enter($owner, $lot, 'SUS123')
            ->assertCreated()->assertJsonPath('data.session.membership', null)->assertJsonPath('data.notices.0.type', 'warning')->json('data.session.id');
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/cancel", ['reason' => 'Prueba de acceso'])->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership}/reactivate")->assertOk()->assertJsonPath('data.membership.status', 'active');
        $this->enter($owner, $lot, 'SUS123')->assertCreated()->assertJsonPath('data.notices.0.type', 'info');

        // Renewed early, the days still paid stay covered and the span grows.
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership}/renew")
            ->assertOk()
            ->assertJsonPath('data.membership.current_period_start', '2026-11-01')
            ->assertJsonPath('data.membership.current_period_end', '2026-12-31')
            ->assertJsonPath('data.membership.periods.0.sequence', 2)
            ->assertJsonPath('data.membership.periods.0.starts_on', '2026-12-01')
            ->assertJsonPath('data.membership.periods.0.origin', 'renewal');

        // A repeated renewal (double click, retry) does not open a second period to charge.
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership}/renew")
            ->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_RENEWED');
        $this->assertSame(2, DB::table('membership_periods')->where('membership_id', $membership)->count());

        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership}/cancel", ['reason' => 'Se mudó'])
            ->assertOk()->assertJsonPath('data.membership.status', 'cancelled')->assertJsonPath('data.membership.ended_on', '2026-11-20');
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership}/renew")
            ->assertUnprocessable()->assertJsonPath('error.code', 'MEMBERSHIP_NOT_CHANGEABLE');
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.cancelled', 'resource_id' => $membership]);
    }

    public function test_the_scheduler_renews_automatically_and_expires_after_the_grace_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $plan = $this->createPlan($owner, ['grace_days' => 2]);
        $auto = $this->sell($owner, $plan, $this->createCustomer($owner), ['AUT123'])->assertCreated()->json('data.membership.id');
        $manual = $this->sell($owner, $plan, $this->createCustomer($owner, ['name' => 'Otro']), ['MAN123'], ['auto_renew' => false])->assertCreated()->json('data.membership.id');

        // November 30 is still the period; nothing happens.
        $this->travelTo(CarbonImmutable::parse('2026-11-30 23:00:00', 'America/Bogota'));
        $this->artisan('memberships:renew')->assertSuccessful();
        $this->assertSame(1, DB::table('membership_periods')->where('membership_id', $auto)->count());

        // December 1: the automatic one renews; the other is within its grace days.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'America/Bogota'));
        $this->artisan('memberships:renew')->assertSuccessful();
        $this->artisan('memberships:renew')->assertSuccessful();
        $this->assertDatabaseHas('memberships', ['id' => $auto, 'status' => 'active']);
        $this->assertSame(2, DB::table('membership_periods')->where('membership_id', $auto)->count());
        $this->assertSame('active', $this->membershipStatus($manual));

        $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00:00', 'America/Bogota'));
        $this->artisan('memberships:renew')->assertSuccessful();
        $this->assertSame('expired', $this->membershipStatus($manual));
        $this->assertDatabaseHas('audit_logs', ['action' => 'membership.expired', 'resource_id' => $manual]);

        // Renewed weeks later, it starts a new cycle today instead of charging days it could not use.
        $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'America/Bogota'));
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$manual}/renew", [], ['Idempotency-Key' => 'renew-manual-1'])
            ->assertOk()
            ->assertJsonPath('data.membership.status', 'active')
            ->assertJsonPath('data.membership.current_period_start', '2026-12-20')
            ->assertJsonPath('data.membership.current_period_end', '2027-01-19');

        // The same attempt repeated (double click, retry after a timeout) changes nothing.
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$manual}/renew", [], ['Idempotency-Key' => 'renew-manual-1'])
            ->assertOk()
            ->assertJsonPath('data.membership.current_period_end', '2027-01-19');
        $this->assertSame(2, DB::table('membership_periods')->where('membership_id', $manual)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'membership.renewed')->where('resource_id', $manual)->count());
    }

    public function test_memberships_are_isolated_and_need_their_permissions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner('Empresa A');
        [$intruder] = $this->createTenantOwner('Empresa B');
        $plan = $this->createPlan($owner);
        $customer = $this->createCustomer($owner);
        $membership = $this->sell($owner, $plan, $customer, ['ISO123'])->assertCreated()->json('data.membership.id');
        $foreignCustomer = $this->createCustomer($intruder, ['name' => 'Ajeno']);

        $operator = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $this->actingAs($operator)->getJson("/api/v1/memberships/{$membership}")->assertOk();
        $this->actingAs($operator)->postJson("/api/v1/memberships/{$membership}/suspend", ['reason' => 'Prueba'])->assertForbidden();
        $this->sell($operator, $plan, $customer, ['OPE123'])->assertForbidden();

        $this->actingAs($intruder)->getJson("/api/v1/memberships/{$membership}")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/memberships/{$membership}/cancel", ['reason' => 'Ajena'])->assertNotFound();
        $this->actingAs($intruder)->getJson('/api/v1/memberships')->assertOk()->assertJsonCount(0, 'data.items');
        $this->sell($intruder, $plan, $foreignCustomer, ['INT123'])->assertNotFound();
        $intruderPlan = $this->createPlan($intruder);
        $this->sell($intruder, $intruderPlan, $customer, ['INT123'])->assertUnprocessable()->assertJsonValidationErrors(['customer_id'], 'error.details');

        $this->actingAs($owner)->getJson('/api/v1/memberships?search=iso-1')->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertSame('active', $this->membershipStatus($membership));
    }
}
