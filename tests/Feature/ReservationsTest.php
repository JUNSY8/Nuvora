<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithParkingSessions;
use Tests\Concerns\InteractsWithReservations;
use Tests\Concerns\InteractsWithTariffs;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ReservationsTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithParking;
    use InteractsWithParkingSessions;
    use InteractsWithReservations;
    use InteractsWithTariffs;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_a_space_reservation_holds_its_space_and_the_arrival_takes_it(): void
    {
        // Wednesday 08:00 in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $reserved = $this->createSpace($owner, $lot, 'R-01', ['is_reservable' => true]);
        $this->createTariff($owner, $lot);

        $reservation = $this->reserve($owner, $lot, 'abc-123', '2026-11-04T10:00', '2026-11-04T12:00', ['space_id' => $reserved])
            ->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.reservation.status', 'confirmed')
            ->assertJsonPath('data.reservation.scope', 'space')
            ->assertJsonPath('data.reservation.space.code', 'R-01')
            ->assertJsonPath('data.reservation.vehicle.plate', 'ABC123')
            // Two hours at $3.000 with the tariff in force at the start.
            ->assertJsonPath('data.reservation.quoted_amount', self::cop(6000))
            ->assertJsonPath('data.reservation.currency', 'COP')
            ->json('data.reservation');
        $this->assertMatchesRegularExpression('/^R-[2-9A-Z]{6}$/', $reservation['code']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.created', 'resource_id' => $reservation['id']]);

        // The space is promised with a 10 min buffer on each side.
        $this->reserve($owner, $lot, 'OTR111', '2026-11-04T11:00', '2026-11-04T13:00', ['space_id' => $reserved])
            ->assertStatus(409)->assertJsonPath('error.code', 'SPACE_ALREADY_RESERVED');
        $this->reserve($owner, $lot, 'OTR111', '2026-11-04T12:05', '2026-11-04T13:00', ['space_id' => $reserved])
            ->assertStatus(409)->assertJsonPath('error.code', 'SPACE_ALREADY_RESERVED');
        $this->reserve($owner, $lot, 'OTR111', '2026-11-04T12:20', '2026-11-04T13:30', ['space_id' => $reserved])->assertCreated();

        // Before its window the space stays available; from the window it is held.
        $this->sweepReservations();
        $this->assertSame('available', $this->spaceStatus($reserved));
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:50:00', 'America/Bogota'));
        $this->sweepReservations();
        $this->assertSame('reserved', $this->spaceStatus($reserved));

        // A walk-in cannot take the held space.
        $this->enter($owner, $lot, 'WLK123', ['space_id' => $reserved])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_NOT_AVAILABLE');

        $this->travel(5)->minutes();
        $session = $this->enter($owner, $lot, 'ABC123')
            ->assertCreated()
            ->assertJsonPath('data.session.space.code', 'R-01')
            ->assertJsonPath('data.session.reservation.code', $reservation['code'])
            ->json('data.session');
        $this->assertSame('active', $this->reservationStatus($reservation['id']));
        $this->assertSame('occupied', $this->spaceStatus($reserved));

        $this->travel(65)->minutes();
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session['id']}/exit")->assertOk();
        $this->assertSame('completed', $this->reservationStatus($reservation['id']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.completed', 'resource_id' => $reservation['id']]);

        $history = $this->actingAs($owner)->getJson("/api/v1/vehicles/{$reservation['vehicle']['id']}/history")->assertOk()->json('data.items');
        $this->assertSame(['exited', 'entered', 'reserved', 'registered'], array_column($history, 'type'));
        $this->assertSame('reservation', $history[3]['data']['source']);
    }

    public function test_zone_and_lot_reservations_respect_capacity(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $north = $this->createZone($owner, $lot, 'N');
        $south = $this->createZone($owner, $lot, 'S');
        $this->createSpace($owner, $lot, 'N-01', ['is_reservable' => true, 'zone_id' => $north]);
        $this->createSpace($owner, $lot, 'N-02', ['is_reservable' => true, 'zone_id' => $north]);
        $this->createSpace($owner, $lot, 'S-01', ['is_reservable' => true, 'zone_id' => $south]);
        $this->createSpace($owner, $lot, 'X-01');

        $this->reserve($owner, $lot, 'ZON001', '2026-11-04T10:00', '2026-11-04T12:00', ['zone_id' => $north])
            ->assertCreated()->assertJsonPath('data.reservation.scope', 'zone')->assertJsonPath('data.reservation.zone.code', 'N');
        $this->reserve($owner, $lot, 'ZON002', '2026-11-04T11:00', '2026-11-04T13:00', ['zone_id' => $north])->assertCreated();
        $this->reserve($owner, $lot, 'ZON003', '2026-11-04T11:30', '2026-11-04T12:30', ['zone_id' => $north])
            ->assertStatus(409)->assertJsonPath('error.code', 'FULLY_BOOKED');

        $this->actingAs($owner)->getJson("/api/v1/reservations/availability?lot_id={$lot}&vehicle_type=car&starts_at=2026-11-04T11:30&ends_at=2026-11-04T12:30")
            ->assertOk()
            ->assertJsonPath('data.pool', 3)
            ->assertJsonPath('data.available', 1)
            ->assertJsonCount(3, 'data.spaces');

        // The lot pool has three reservable spaces; two are taken at 11:30.
        $this->reserve($owner, $lot, 'LOT001', '2026-11-04T11:30', '2026-11-04T12:30')->assertCreated()->assertJsonPath('data.reservation.scope', 'lot');
        $this->reserve($owner, $lot, 'LOT002', '2026-11-04T11:30', '2026-11-04T12:30')
            ->assertStatus(409)->assertJsonPath('error.code', 'FULLY_BOOKED');
        // Later in the day there is room again (12:00 + 10 min buffer).
        $this->reserve($owner, $lot, 'LOT002', '2026-11-04T13:20', '2026-11-04T14:30')->assertCreated();

        // A truck finds no reservable space that accepts it.
        $this->reserve($owner, $lot, 'TRK001', '2026-11-04T15:00', '2026-11-04T16:00', ['vehicle_type' => 'truck'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'NO_RESERVABLE_SPACES');

        // An arrival with a zone reservation is placed in its zone.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:55:00', 'America/Bogota'));
        $this->enter($owner, $lot, 'ZON001')->assertCreated()->assertJsonPath('data.session.space.code', 'N-01');
        // A walk-in uses non-reservable spaces first.
        $this->enter($owner, $lot, 'WLK001')->assertCreated()->assertJsonPath('data.session.space.code', 'X-01');
    }

    public function test_holds_expire_and_no_shows_release_the_space(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'R-01', ['is_reservable' => true]);

        $hold = $this->reserve($owner, $lot, 'HLD001', '2026-11-04T10:00', '2026-11-04T11:00', ['confirm' => false])
            ->assertCreated()->assertJsonPath('data.reservation.status', 'pending')->json('data.reservation');
        $this->assertNotNull($hold['hold_expires_at']);
        $this->reserve($owner, $lot, 'HLD002', '2026-11-04T10:00', '2026-11-04T11:00')
            ->assertStatus(409)->assertJsonPath('error.code', 'FULLY_BOOKED');

        // 16 minutes later the hold no longer takes capacity, even before the scheduler runs.
        $this->travel(16)->minutes();
        $taken = $this->reserve($owner, $lot, 'HLD002', '2026-11-04T10:00', '2026-11-04T11:00', ['space_id' => $space])->assertCreated()->json('data.reservation');
        $this->assertSame('expired', $this->reservationStatus($hold['id']));
        $this->actingAs($owner)->postJson("/api/v1/reservations/{$hold['id']}/confirm")
            ->assertUnprocessable()->assertJsonPath('error.code', 'RESERVATION_NOT_CHANGEABLE');

        // A pending hold confirmed in time becomes final.
        $pending = $this->reserve($owner, $lot, 'HLD003', '2026-11-04T13:00', '2026-11-04T14:00', ['confirm' => false])->assertCreated()->json('data.reservation.id');
        $this->actingAs($owner)->postJson("/api/v1/reservations/{$pending}/confirm")
            ->assertOk()->assertJsonPath('data.reservation.status', 'confirmed')->assertJsonPath('data.reservation.hold_expires_at', null);

        // The vehicle does not arrive within 30 minutes of the start.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:55:00', 'America/Bogota'));
        $this->sweepReservations();
        $this->assertSame('reserved', $this->spaceStatus($space));
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:31:00', 'America/Bogota'));
        $this->sweepReservations();
        $this->assertSame('no_show', $this->reservationStatus($taken['id']));
        $this->assertSame('available', $this->spaceStatus($space));
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.no_show', 'resource_id' => $taken['id']]);

        // Arriving after the tolerance is a regular entry, without the reservation.
        $this->enter($owner, $lot, 'HLD002')->assertCreated()->assertJsonPath('data.session.reservation', null);
    }

    public function test_requests_that_break_the_rules_are_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'R-01', ['is_reservable' => true]);
        $this->createSpace($owner, $lot, 'R-02', ['is_reservable' => true]);
        $plain = $this->createSpace($owner, $lot, 'A-01');

        $this->reserve($owner, $lot, 'RUL001', '2026-11-04T07:00', '2026-11-04T09:00')
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_WINDOW');
        $this->reserve($owner, $lot, 'RUL001', '2026-11-04T10:00', '2026-11-04T10:10')
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_WINDOW');
        $this->reserve($owner, $lot, 'RUL001', '2026-11-04T10:00', '2026-11-04T11:00', ['space_id' => $plain])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_NOT_RESERVABLE');
        $this->reserve($owner, $lot, 'RUL001', '2026-11-04T10:00', '2026-11-04T11:00', ['space_id' => $space, 'vehicle_type' => 'truck'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SPACE_INCOMPATIBLE');

        // The same vehicle cannot hold two overlapping reservations.
        $this->reserve($owner, $lot, 'RUL001', '2026-11-04T10:00', '2026-11-04T11:00')->assertCreated();
        $this->reserve($owner, $lot, 'rul-001', '2026-11-04T10:30', '2026-11-04T11:30')
            ->assertStatus(409)->assertJsonPath('error.code', 'VEHICLE_ALREADY_RESERVED');

        // Idempotency: a retry returns the same booking; reusing the key for another plate fails.
        $first = $this->reserve($owner, $lot, 'IDE001', '2026-11-04T14:00', '2026-11-04T15:00', [], ['Idempotency-Key' => 'booking-ide001-1'])
            ->assertCreated()->json('data.reservation.id');
        $this->reserve($owner, $lot, 'IDE001', '2026-11-04T14:00', '2026-11-04T15:00', [], ['Idempotency-Key' => 'booking-ide001-1'])
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.reservation.id', $first);
        $this->reserve($owner, $lot, 'IDE002', '2026-11-04T14:00', '2026-11-04T15:00', [], ['Idempotency-Key' => 'booking-ide001-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        // Blocked vehicles cannot book.
        $blocked = $this->createVehicle($owner, 'BLK123');
        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$blocked}/status", ['status' => 'blocked', 'reason' => 'Daños'])->assertOk();
        $this->reserve($owner, $lot, 'BLK123', '2026-11-04T16:00', '2026-11-04T17:00')
            ->assertUnprocessable()->assertJsonPath('error.code', 'VEHICLE_BLOCKED');

        // Operating hours: closed on Sundays.
        $days = array_map(fn (int $day): array => $day === 7 ? ['day_of_week' => 7, 'mode' => 'closed'] : ['day_of_week' => $day, 'mode' => 'open_24h'], range(1, 7));
        $this->actingAs($owner)->putJson("/api/v1/parking-lots/{$lot}/operating-hours", ['days' => $days])->assertOk();
        $this->reserve($owner, $lot, 'SUN001', '2026-11-08T10:00', '2026-11-08T11:00')
            ->assertUnprocessable()->assertJsonPath('error.code', 'OUTSIDE_OPERATING_HOURS');

        // A lot can stop taking reservations.
        $policy = ['lot_id' => $lot, 'enabled' => false, 'buffer_minutes' => 10, 'hold_minutes' => 15, 'arrival_tolerance_minutes' => 30, 'min_duration_minutes' => 30, 'max_duration_minutes' => 1440, 'max_advance_days' => 60, 'within_operating_hours' => true];
        $this->actingAs($owner)->putJson('/api/v1/reservations/policy', $policy)->assertOk()->assertJsonPath('data.policy.enabled', false);
        $this->reserve($owner, $lot, 'OFF001', '2026-11-05T10:00', '2026-11-05T11:00')
            ->assertUnprocessable()->assertJsonPath('error.code', 'RESERVATIONS_DISABLED');
        $this->actingAs($owner)->getJson("/api/v1/reservations/policy?lot_id={$lot}")->assertOk()->assertJsonPath('data.policy.enabled', false);
        $this->actingAs($owner)->putJson('/api/v1/reservations/policy', [...$policy, 'min_duration_minutes' => 120, 'max_duration_minutes' => 60])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_POLICY');

        $this->assertSame(2, DB::table('reservations')->count());
    }

    public function test_cancelling_releases_the_space_and_a_cancelled_entry_restores_the_reservation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:55:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'R-01', ['is_reservable' => true]);
        $second = $this->createSpace($owner, $lot, 'R-02', ['is_reservable' => true]);

        // A window that already started holds its space at once.
        $cancelled = $this->reserve($owner, $lot, 'CAN001', '2026-11-04T10:00', '2026-11-04T11:00', ['space_id' => $space])->assertCreated()->json('data.reservation.id');
        $this->assertSame('reserved', $this->spaceStatus($space));

        $this->actingAs($owner)->postJson("/api/v1/reservations/{$cancelled}/cancel", ['reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');
        $this->actingAs($owner)->postJson("/api/v1/reservations/{$cancelled}/cancel", ['reason' => 'El cliente llamó'])
            ->assertOk()->assertJsonPath('data.reservation.status', 'cancelled')->assertJsonPath('data.reservation.cancel_reason', 'El cliente llamó');
        $this->assertSame('available', $this->spaceStatus($space));

        $kept = $this->reserve($owner, $lot, 'CAN002', '2026-11-04T10:00', '2026-11-04T11:00', ['space_id' => $second])->assertCreated()->json('data.reservation.id');
        $session = $this->enter($owner, $lot, 'CAN002')->assertCreated()->assertJsonPath('data.session.space.code', 'R-02')->json('data.session.id');
        $this->actingAs($owner)->postJson("/api/v1/reservations/{$kept}/cancel", ['reason' => 'Ya entró'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'RESERVATION_NOT_CHANGEABLE');

        // The entry was a mistake: the reservation waits for the vehicle again.
        $this->actingAs($owner)->postJson("/api/v1/parking-sessions/{$session}/cancel", ['reason' => 'Placa equivocada'])->assertOk();
        $this->assertSame('confirmed', $this->reservationStatus($kept));
        $this->sweepReservations();
        $this->assertSame('reserved', $this->spaceStatus($second));

        $this->actingAs($owner)->getJson("/api/v1/reservations?lot_id={$lot}&status[]=confirmed")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.vehicle.plate', 'CAN002');
        $code = DB::table('reservations')->where('id', $kept)->value('code');
        $this->actingAs($owner)->getJson('/api/v1/reservations?search='.strtolower(str_replace('-', '', (string) $code)))
            ->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_roles_and_tenants_limit_reservations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner('Empresa A');
        [$intruder] = $this->createTenantOwner('Empresa B');
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'R-01', ['is_reservable' => true]);
        $reservation = $this->reserve($owner, $lot, 'ISO123', '2026-11-04T10:00', '2026-11-04T11:00')->assertCreated()->json('data.reservation.id');
        $otherLot = $this->createLot($intruder, ['code' => 'otra']);
        $this->createSpace($intruder, $otherLot, 'B-01', ['is_reservable' => true]);

        $operator = User::factory()->create();
        $accountant = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $this->addMember($owner, $tenant, $accountant, 'accountant');

        $this->actingAs($operator)->getJson("/api/v1/reservations/{$reservation}")->assertOk();
        $this->reserve($operator, $lot, 'OPE123', '2026-11-04T12:00', '2026-11-04T13:00')->assertForbidden();
        $this->actingAs($operator)->postJson("/api/v1/reservations/{$reservation}/cancel", ['reason' => 'Prueba'])->assertForbidden();
        $this->actingAs($operator)->putJson('/api/v1/reservations/policy', ['lot_id' => $lot])->assertForbidden();
        $this->actingAs($accountant)->getJson('/api/v1/reservations')->assertForbidden();

        $this->actingAs($intruder)->getJson("/api/v1/reservations/{$reservation}")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/reservations/{$reservation}/cancel", ['reason' => 'Ajena'])->assertNotFound();
        $this->actingAs($intruder)->getJson('/api/v1/reservations')->assertOk()->assertJsonCount(0, 'data.items');
        $this->reserve($intruder, $lot, 'INT123', '2026-11-04T12:00', '2026-11-04T13:00')
            ->assertUnprocessable()->assertJsonValidationErrors(['lot_id'], 'error.details');
        $this->reserve($intruder, $otherLot, 'INT123', '2026-11-04T12:00', '2026-11-04T13:00', ['space_id' => $space])
            ->assertUnprocessable()->assertJsonValidationErrors(['space_id'], 'error.details');
        $this->actingAs($intruder)->getJson("/api/v1/reservations/availability?lot_id={$lot}&vehicle_type=car&starts_at=2026-11-04T12:00&ends_at=2026-11-04T13:00")
            ->assertUnprocessable()->assertJsonValidationErrors(['lot_id'], 'error.details');

        $this->assertSame('confirmed', $this->reservationStatus($reservation));
    }

    public function test_the_database_rejects_two_reservations_of_one_space(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The exclusion constraints exist only on PostgreSQL.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'R-01', ['is_reservable' => true]);
        $first = $this->reserve($owner, $lot, 'DBR001', '2026-11-04T10:00', '2026-11-04T12:00', ['space_id' => $space])->assertCreated()->json('data.reservation.id');
        $copy = (array) DB::table('reservations')->where('id', $first)->first();

        $this->expectException(QueryException::class);

        // Simulates a second clerk that skipped every application check.
        DB::table('reservations')->insert([
            ...$copy,
            'id' => strtolower((string) Str::ulid()),
            'code' => 'R-DBTEST',
            'vehicle_id' => $this->createVehicle($owner, 'DBR002'),
            'plate' => 'DBR002',
            'idempotency_key' => null,
        ]);
    }
}
