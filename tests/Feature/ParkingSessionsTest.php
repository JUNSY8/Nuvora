<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Infrastructure\Realtime\LiveUpdates;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithParkingSessions;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ParkingSessionsTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithParking;
    use InteractsWithParkingSessions;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_entry_registers_a_visitor_assigns_a_compatible_space_and_exit_frees_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $moto = $this->createSpace($owner, $lot, 'M-01', ['type' => 'motorcycle']);
        $car = $this->createSpace($owner, $lot, 'A-02');
        $this->createSpace($owner, $lot, 'D-01', ['type' => 'disabled']);
        $entryGate = $this->createGate($owner, $lot, 'IN-01', 'entry');
        $exitGate = $this->createGate($owner, $lot, 'OUT-01', 'exit');

        $session = $this->enter($owner, $lot, 'abc-123', ['gate_id' => $entryGate])
            ->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.session.status', 'open')
            ->assertJsonPath('data.session.vehicle.plate', 'ABC123')
            ->assertJsonPath('data.session.vehicle.type', 'car')
            ->assertJsonPath('data.session.space.code', 'A-02')
            ->assertJsonPath('data.session.entry_gate.code', 'IN-01')
            ->assertJsonPath('data.session.entry_source', 'manual')
            ->assertJsonPath('data.session.opened_by.name', $owner->name)
            ->json('data.session');

        $this->assertSame('occupied', $this->spaceStatus($car));
        $this->assertSame('available', $this->spaceStatus($moto));
        $this->assertDatabaseHas('vehicles', ['plate' => 'ABC123', 'country' => 'CO', 'type' => 'car']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'parking_session.opened', 'resource_id' => $session['id']]);

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()
            ->assertJsonPath('data.open', 1)
            ->assertJsonPath('data.entries_today', 1)
            ->assertJsonPath('data.occupancy.by_status.occupied', 1)
            ->assertJsonPath('data.occupancy.available', 2);

        // A started minute counts: 1 h 34 min 10 s is 95 minutes.
        $this->travel(5650)->seconds();

        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session['id']}/exit", ['gate_id' => $exitGate])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'closed')
            ->assertJsonPath('data.session.exit_gate.code', 'OUT-01')
            ->assertJsonPath('data.session.duration_minutes', 95)
            ->assertJsonPath('data.session.closed_by.name', $owner->name);

        $this->assertSame('available', $this->spaceStatus($car));

        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session['id']}/exit")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SESSION_NOT_OPEN');

        $history = $this->actingAs($owner)->getJson("/api/v1/vehicles/{$session['vehicle']['id']}/history")->assertOk();
        $this->assertSame(['exited', 'entered', 'registered'], array_column($history->json('data.items'), 'type'));
        $this->assertSame('entry', $history->json('data.items.2.data.source'));

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()
            ->assertJsonPath('data.open', 0)
            ->assertJsonPath('data.exits_today', 1)
            ->assertJsonPath('data.average_stay_minutes_today', 95);
    }

    public function test_automatic_assignment_follows_vehicle_type_and_reports_a_full_lot(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'M-01', ['type' => 'motorcycle']);
        $this->createSpace($owner, $lot, 'E-01', ['type' => 'ev']);
        $this->createVehicle($owner, 'ELE123', ['is_electric' => true]);

        // Known vehicles keep their registered type; the requested one is ignored.
        $this->enter($owner, $lot, 'ELE123', ['vehicle_type' => 'truck'])
            ->assertCreated()
            ->assertJsonPath('data.session.vehicle.type', 'car')
            ->assertJsonPath('data.session.space.code', 'E-01');

        $this->enter($owner, $lot, 'XYZ12D', ['vehicle_type' => 'motorcycle'])
            ->assertCreated()
            ->assertJsonPath('data.session.space.code', 'M-01');

        $this->enter($owner, $lot, 'CAR001')->assertCreated()->assertJsonPath('data.session.space.code', 'A-01');

        $this->enter($owner, $lot, 'CAR002')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'NO_SPACE_AVAILABLE');

        // Nothing from the failed entry is kept: not even the visitor vehicle.
        $this->assertDatabaseMissing('vehicles', ['plate' => 'CAR002']);

        $this->enter($owner, $lot, 'CAR002', ['auto_assign' => false])
            ->assertCreated()
            ->assertJsonPath('data.session.space', null);

        $this->enter($owner, $lot, 'NEW999', ['vehicle_type' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_type'], 'error.details');

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()
            ->assertJsonPath('data.open', 4)
            ->assertJsonPath('data.open_without_space', 1)
            ->assertJsonPath('data.occupancy.rate', 100);
    }

    public function test_entry_rules_reject_blocked_vehicles_duplicates_and_bad_choices(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $car = $this->createSpace($owner, $lot, 'A-01');
        $moto = $this->createSpace($owner, $lot, 'M-01', ['type' => 'motorcycle']);
        $truck = $this->createSpace($owner, $lot, 'T-01', ['type' => 'truck']);
        $exitGate = $this->createGate($owner, $lot, 'OUT-01', 'exit');
        $closedGate = $this->createGate($owner, $lot, 'IN-02', 'entry', ['status' => 'maintenance']);
        $blocked = $this->createVehicle($owner, 'BLK001');
        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$blocked}/status", ['status' => 'blocked', 'reason' => 'Deuda pendiente'])->assertOk();

        $this->enter($owner, $lot, 'BLK001')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VEHICLE_BLOCKED')
            ->assertJsonPath('error.message', 'El vehículo BLK001 está bloqueado: Deuda pendiente.');

        $this->enter($owner, $lot, 'TRK001', ['vehicle_type' => 'truck', 'space_id' => $moto])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SPACE_INCOMPATIBLE');
        $this->assertSame('available', $this->spaceStatus($moto));

        $this->enter($owner, $lot, 'GAT001', ['gate_id' => $exitGate])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gate_id'], 'error.details');
        $this->enter($owner, $lot, 'GAT001', ['gate_id' => $closedGate])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gate_id'], 'error.details');

        $this->enter($owner, $lot, 'DUP001', ['space_id' => $car])->assertCreated();
        $this->enter($owner, $lot, 'dup 001')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VEHICLE_ALREADY_INSIDE');
        $this->enter($owner, $lot, 'OTR001', ['space_id' => $car])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SPACE_NOT_AVAILABLE');

        // A motorcycle may use a car space chosen by hand, but a truck space is not offered to it.
        $this->enter($owner, $lot, 'MOT01D', ['vehicle_type' => 'motorcycle', 'space_id' => $truck])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SPACE_INCOMPATIBLE');

        $this->enter($owner, $lot, 'AB#1')->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_PLATE');

        $this->actingAs($owner)->patchJson("/api/v1/parking-lots/{$lot}", $this->lotPayload(['status' => 'maintenance']))->assertOk();
        $this->enter($owner, $lot, 'LOT001')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'LOT_NOT_OPERATING');

        $this->assertDatabaseCount('parking_sessions', 1);
    }

    public function test_idempotency_keys_replay_entries_and_exits_without_acting_twice(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');
        $key = ['Idempotency-Key' => 'entry-'.Str::uuid()];

        $first = $this->enter($owner, $lot, 'IDE123', [], $key)->assertCreated()->json('data.session.id');

        $this->enter($owner, $lot, 'ide-123', [], $key)
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.session.id', $first);

        $this->enter($owner, $lot, 'OTHER1', [], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->enter($owner, $lot, 'IDE124', [], ['Idempotency-Key' => 'bad key!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key'], 'error.details');

        $this->assertDatabaseCount('parking_sessions', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'parking_session.opened')->count());

        $exitKey = 'exit-'.Str::uuid();
        $this->actingAs($owner)->withHeader('Idempotency-Key', $exitKey)->postJson("/api/v1/parking-sessions/{$first}/exit")
            ->assertOk()->assertJsonPath('data.replayed', false);
        $this->actingAs($owner)->withHeader('Idempotency-Key', $exitKey)->postJson("/api/v1/parking-sessions/{$first}/exit")
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.session.status', 'closed');
        $this->flushHeaders();

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'parking_session.closed')->count());
        $this->assertSame(1, DB::table('vehicle_events')->where('type', 'exited')->count());
    }

    public function test_sessions_can_be_moved_and_cancelled_with_a_reason(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $first = $this->createSpace($owner, $lot, 'A-01');
        $second = $this->createSpace($owner, $lot, 'A-02');
        $moto = $this->createSpace($owner, $lot, 'M-01', ['type' => 'motorcycle']);
        $session = $this->enter($owner, $lot, 'MOV123', ['auto_assign' => false])->assertCreated()->json('data.session.id');

        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/space", ['space_id' => $first])
            ->assertOk()->assertJsonPath('data.session.space.code', 'A-01');
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/space", ['space_id' => $second])
            ->assertOk()->assertJsonPath('data.session.space.code', 'A-02');
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/space", ['space_id' => $moto])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_INCOMPATIBLE');

        $this->assertSame('available', $this->spaceStatus($first));
        $this->assertSame('occupied', $this->spaceStatus($second));
        $this->assertSame('available', $this->spaceStatus($moto));

        // An occupied space only changes through its session.
        $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/spaces/{$second}/status", ['status' => 'maintenance'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_TRANSITION_NOT_ALLOWED');

        $supervisor = User::factory()->create();
        $this->addMember($owner, $tenant, $supervisor, 'supervisor');

        $this->actingAs($supervisor)->postJson("/api/v1/parking-sessions/{$session}/cancel", ['reason' => 'no'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');
        $this->actingAs($supervisor)->postJson("/api/v1/parking-sessions/{$session}/cancel", ['reason' => 'Placa digitada por error'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'cancelled')
            ->assertJsonPath('data.session.cancel_reason', 'Placa digitada por error');

        $this->assertSame('available', $this->spaceStatus($second));
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/exit")
            ->assertUnprocessable()->assertJsonPath('error.code', 'SESSION_NOT_OPEN');
        $this->assertDatabaseHas('audit_logs', ['action' => 'parking_session.cancelled', 'resource_id' => $session]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'parking_session.space_changed', 'resource_id' => $session]);

        // A space with history cannot be deleted; it can still go out of service.
        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}/spaces/{$second}")
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_HAS_HISTORY');
        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}/spaces/{$moto}")->assertOk();
    }

    public function test_roles_limit_who_registers_views_and_cancels_sessions(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');

        $operator = User::factory()->create();
        $viewer = User::factory()->create();
        $accountant = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $this->addMember($owner, $tenant, $viewer, 'report_viewer');
        $this->addMember($owner, $tenant, $accountant, 'accountant');

        // The operator admits a visitor without vehicles.create.
        $session = $this->enter($operator, $lot, 'OPE123')->assertCreated()->json('data.session.id');

        $this->actingAs($operator)->getJson("/api/v1/parking-sessions?lot_id={$lot}&status=open")->assertOk()->assertJsonCount(1, 'data.items');
        $this->actingAs($operator)->postJson("/api/v1/parking-sessions/{$session}/cancel", ['reason' => 'Prueba de permiso'])->assertForbidden();

        $this->actingAs($viewer)->getJson("/api/v1/parking-sessions/{$session}")->assertOk()->assertJsonPath('data.session.vehicle.plate', 'OPE123');
        $this->enter($viewer, $lot, 'VIE123')->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/parking-sessions/{$session}/exit")->assertForbidden();

        $this->actingAs($accountant)->getJson('/api/v1/parking-sessions')->assertForbidden();

        $this->actingAs($operator)->postJson("/api/v1/parking-sessions/{$session}/exit")->assertOk();
    }

    public function test_sessions_are_isolated_between_tenants(): void
    {
        [$owner] = $this->createTenantOwner('Empresa A');
        [$intruder] = $this->createTenantOwner('Empresa B');
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'A-01');
        $session = $this->enter($owner, $lot, 'ISO123')->assertCreated()->json('data.session.id');

        $otherLot = $this->createLot($intruder, ['code' => 'otra']);
        $this->createSpace($intruder, $otherLot, 'B-01');

        $this->actingAs($intruder)->getJson("/api/v1/parking-sessions/{$session}")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/parking-sessions/{$session}/exit")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/parking-sessions/{$session}/cancel", ['reason' => 'Intento ajeno'])->assertNotFound();
        $this->actingAs($intruder)->getJson('/api/v1/parking-sessions')->assertOk()->assertJsonCount(0, 'data.items');
        $this->actingAs($intruder)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertUnprocessable()->assertJsonValidationErrors(['lot_id'], 'error.details');

        // Another tenant's lot or space in the body is a validation answer, never a write.
        $this->enter($intruder, $lot, 'ISO123')->assertUnprocessable()->assertJsonValidationErrors(['lot_id'], 'error.details');
        $this->enter($intruder, $otherLot, 'ISO999', ['space_id' => $space])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_NOT_FOUND');

        // The same plate is another vehicle in another tenant.
        $this->enter($intruder, $otherLot, 'ISO123')->assertCreated();

        $this->assertSame('occupied', $this->spaceStatus($space));
        $this->assertSame(1, DB::table('vehicles')->where('plate', 'ISO123')->where('tenant_id', '!=', DB::table('parking_lots')->where('id', $lot)->value('tenant_id'))->count());
    }

    public function test_history_filters_by_status_plate_and_local_day(): void
    {
        // 23:30 in Bogotá is already the next day in UTC: days must follow the lot's clock.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:30:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');

        $closed = $this->enter($owner, $lot, 'HIS111')->assertCreated()->json('data.session.id');
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$closed}/exit")->assertOk();
        $this->enter($owner, $lot, 'HIS222')->assertCreated();

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions?lot_id={$lot}&status=open")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.vehicle.plate', 'HIS222');
        $this->actingAs($owner)->getJson('/api/v1/parking-sessions?search=his-1')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.status', 'closed');

        $today = '2026-11-04';
        $tomorrow = '2026-11-05';

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions?lot_id={$lot}&from_date={$today}&to_date={$today}")
            ->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions?lot_id={$lot}&from_date={$tomorrow}&to_date={$tomorrow}")
            ->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()->assertJsonPath('data.entries_today', 2)->assertJsonPath('data.exits_today', 1);
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions?from_date={$today}")
            ->assertUnprocessable()->assertJsonValidationErrors(['lot_id'], 'error.details');
    }

    public function test_live_versions_change_when_operations_happen(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'A-01');

        $before = $this->actingAs($owner)->getJson("/api/v1/live?lot_id={$lot}")->assertOk()->json('data');
        $this->assertSame('none', $before['lot']);

        $this->enter($owner, $lot, 'LIV123')->assertCreated();
        $afterEntry = $this->actingAs($owner)->getJson("/api/v1/live?lot_id={$lot}")->assertOk()->json('data');
        $this->assertNotSame($before['lot'], $afterEntry['lot']);
        $this->assertNotSame($before['tenant'], $afterEntry['tenant']);

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()->assertJsonPath('data.version', $afterEntry['lot']);

        $other = $this->createSpace($owner, $lot, 'A-02');
        $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/spaces/{$other}/status", ['status' => 'blocked'])->assertOk();
        $afterBlock = $this->actingAs($owner)->getJson("/api/v1/live?lot_id={$lot}")->assertOk()->json('data');
        $this->assertNotSame($afterEntry['lot'], $afterBlock['lot']);
        $this->assertSame('occupied', $this->spaceStatus($space));

        $this->assertSame(LiveUpdates::lot('t', 'l'), 'tenant.t.lot.l');
    }

    public function test_the_database_rejects_two_open_sessions_on_one_space(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'A-01');
        $session = $this->enter($owner, $lot, 'DBA123', ['space_id' => $space])->assertCreated()->json('data.session');
        $copy = (array) DB::table('parking_sessions')->where('id', $session['id'])->first();

        $this->expectException(UniqueConstraintViolationException::class);

        // Simulates a second operator that skipped every application check.
        DB::table('parking_sessions')->insert([
            ...$copy,
            'id' => strtolower((string) Str::ulid()),
            'vehicle_id' => $this->createVehicle($owner, 'DBA124'),
            'plate' => 'DBA124',
            'entry_idempotency_key' => null,
        ]);
    }
}
