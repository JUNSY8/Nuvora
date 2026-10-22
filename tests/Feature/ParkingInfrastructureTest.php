<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Persistence\AuditLog;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Parking\Domain\SpaceStatus;
use App\Modules\Parking\Infrastructure\Persistence\ParkingLot;
use App\Modules\Parking\Infrastructure\Persistence\ParkingSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ParkingInfrastructureTest extends TestCase
{
    use InteractsWithParking;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_owner_creates_a_lot_with_a_normalized_code_and_a_default_schedule(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        [, $otherTenant] = $this->createTenantOwner('Otra empresa');

        $response = $this->actingAs($owner)->postJson('/api/v1/parking-lots', $this->lotPayload([
            'code' => ' sede norte ',
            'tenant_id' => $otherTenant->id,
            'latitude' => 4.6097102,
            'longitude' => -74.081749,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.lot.code', 'SEDE-NORTE')
            ->assertJsonPath('data.lot.currency', 'COP')
            ->assertJsonPath('data.lot.occupancy.capacity', 0)
            ->assertJsonCount(7, 'data.lot.operating_hours')
            ->assertJsonPath('data.lot.operating_hours.0.mode', 'open_24h');

        $lot = ParkingLot::query()->findOrFail($response->json('data.lot.id'));
        $this->assertSame($tenant->id, $lot->tenant_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'parking_lot.created',
            'tenant_id' => $tenant->id,
            'resource_id' => $lot->id,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/parking-lots', $this->lotPayload(['code' => 'SEDE-NORTE']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.code.0', 'Ya existe un parqueadero con ese código.');
    }

    public function test_lot_validation_rejects_bad_currency_timezone_and_half_coordinates(): void
    {
        [$owner] = $this->createTenantOwner();

        $this->actingAs($owner)
            ->postJson('/api/v1/parking-lots', $this->lotPayload([
                'currency' => 'PESOS',
                'timezone' => 'Mars/Base',
                'latitude' => 4.6,
                'grace_period_minutes' => 999,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency', 'timezone', 'longitude', 'grace_period_minutes'], 'error.details');
    }

    public function test_a_tenant_cannot_see_or_change_the_parking_of_another_tenant(): void
    {
        [$ownerA] = $this->createTenantOwner('Empresa A');
        [$ownerB] = $this->createTenantOwner('Empresa B');

        $lotA = $this->createLot($ownerA);
        $spaceA = $this->createSpace($ownerA, $lotA, 'A-1');
        $this->createLot($ownerB, ['code' => 'otra', 'name' => 'Sede B']);

        $this->actingAs($ownerB)->getJson('/api/v1/parking-lots')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Sede B');

        $this->actingAs($ownerB)->getJson("/api/v1/parking-lots/{$lotA}")->assertNotFound();
        $this->actingAs($ownerB)->getJson("/api/v1/parking-lots/{$lotA}/spaces")->assertNotFound();
        $this->actingAs($ownerB)->patchJson("/api/v1/parking-lots/{$lotA}", $this->lotPayload(['name' => 'Tomada']))->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/v1/parking-lots/{$lotA}/zones", ['name' => 'X', 'code' => 'X', 'is_active' => true])->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/v1/parking-lots/{$lotA}/spaces/{$spaceA}/status", ['status' => 'blocked'])->assertNotFound();
        $this->actingAs($ownerB)->deleteJson("/api/v1/parking-lots/{$lotA}/spaces/{$spaceA}")->assertNotFound();
        $this->actingAs($ownerB)->deleteJson("/api/v1/parking-lots/{$lotA}")->assertNotFound();

        $this->assertSame('Sede Centro', ParkingLot::query()->findOrFail($lotA)->name);
        $this->assertSame(SpaceStatus::Available, ParkingSpace::query()->findOrFail($spaceA)->status);
    }

    public function test_a_space_cannot_be_moved_into_a_zone_of_another_lot(): void
    {
        [$owner] = $this->createTenantOwner();
        $lotA = $this->createLot($owner);
        $lotB = $this->createLot($owner, ['code' => 'norte', 'name' => 'Sede Norte']);

        $zoneB = $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lotB}/zones", ['name' => 'Motos', 'code' => 'motos', 'is_active' => true])
            ->assertCreated()
            ->json('data.zone.id');

        $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lotA}/spaces", [
                'code' => 'A-1',
                'type' => 'motorcycle',
                'zone_id' => $zoneB,
                'is_reservable' => false,
                'is_assignable' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.zone_id.0', 'La zona no pertenece a este parqueadero.');
    }

    public function test_permissions_separate_viewing_from_configuring(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $cashier = User::factory()->create();
        $accountant = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $this->addMember($owner, $tenant, $accountant, 'accountant');
        $lot = $this->createLot($owner);

        $this->actingAs($cashier)->getJson("/api/v1/parking-lots/{$lot}/spaces")->assertOk();
        $this->actingAs($cashier)->postJson('/api/v1/parking-lots', $this->lotPayload(['code' => 'x']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($cashier)->deleteJson("/api/v1/parking-lots/{$lot}")->assertForbidden();
        $this->actingAs($accountant)->getJson('/api/v1/parking-lots')->assertForbidden();
    }

    public function test_bulk_creation_is_all_or_nothing_and_reports_taken_codes(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);

        $range = fn (int $from, int $to): array => [
            'prefix' => 'a-',
            'from' => $from,
            'to' => $to,
            'padding' => 3,
            'type' => 'car',
            'is_reservable' => true,
            'is_assignable' => false,
        ];

        $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/spaces/bulk", $range(1, 20))
            ->assertCreated()
            ->assertJsonPath('data.created', 20);

        $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/spaces/bulk", $range(18, 25))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.prefix.0', 'Estos códigos ya existen en este parqueadero: A-018, A-019, A-020.');

        $this->actingAs($owner)->postJson("/api/v1/parking-lots/{$lot}/spaces/bulk", $range(1, 600))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PARKING_RULE');

        $this->assertSame(20, ParkingSpace::query()->where('parking_lot_id', $lot)->count());

        $this->actingAs($owner)->getJson("/api/v1/parking-lots/{$lot}/spaces?search=a-01&per_page=5")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 10)
            ->assertJsonPath('data.items.0.code', 'A-010');
    }

    public function test_space_status_follows_the_state_machine_and_is_audited(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'B-1');

        $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lot}/spaces/{$space}/status", ['status' => 'blocked', 'reason' => 'Derrame de aceite'])
            ->assertOk()
            ->assertJsonPath('data.space.status', 'blocked')
            ->assertJsonPath('data.space.manual_transitions', ['available', 'maintenance', 'out_of_service']);

        // Repeating the same request is harmless and is not audited twice.
        $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lot}/spaces/{$space}/status", ['status' => 'blocked'])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'parking_space.status_changed')
            ->count());

        $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lot}/spaces/{$space}/status", ['status' => 'occupied'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SPACE_TRANSITION_NOT_ALLOWED');

        ParkingSpace::query()->whereKey($space)->update(['status' => SpaceStatus::Occupied->value]);

        $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lot}/spaces/{$space}/status", ['status' => 'available'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SPACE_TRANSITION_NOT_ALLOWED');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/parking-lots/{$lot}/spaces/{$space}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'SPACE_IN_USE');

        $this->actingAs($owner)->getJson("/api/v1/parking-lots/{$lot}")
            ->assertOk()
            ->assertJsonPath('data.lot.occupancy.in_use', 1)
            ->assertJsonPath('data.lot.occupancy.rate', 100);
    }

    public function test_operating_hours_are_replaced_as_a_full_week(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);

        $week = array_map(fn (int $day): array => [
            'day_of_week' => $day,
            'mode' => $day === 7 ? 'closed' : 'scheduled',
            'opens_at' => $day === 7 ? null : '18:00',
            'closes_at' => $day === 7 ? null : '02:00',
        ], range(1, 7));

        $this->actingAs($owner)->putJson("/api/v1/parking-lots/{$lot}/operating-hours", ['days' => $week])
            ->assertOk()
            ->assertJsonPath('data.operating_hours.0.opens_at', '18:00')
            ->assertJsonPath('data.operating_hours.6.mode', 'closed');

        $week[0]['closes_at'] = '18:00';

        $this->actingAs($owner)->putJson("/api/v1/parking-lots/{$lot}/operating-hours", ['days' => $week])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PARKING_RULE');

        $this->actingAs($owner)->putJson("/api/v1/parking-lots/{$lot}/operating-hours", ['days' => array_slice($week, 1)])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseHas('parking_lot_operating_hours', [
            'parking_lot_id' => $lot,
            'day_of_week' => 1,
            'mode' => 'scheduled',
        ]);
    }

    public function test_infrastructure_in_use_cannot_be_deleted(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $zone = $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lot}/zones", ['name' => 'General', 'code' => 'gen', 'is_active' => true])
            ->json('data.zone.id');
        $space = $this->createSpace($owner, $lot, 'G-1', ['zone_id' => $zone]);
        $gate = $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lot}/gates", ['name' => 'Entrada', 'code' => 'in-1', 'direction' => 'entry', 'status' => 'active'])
            ->assertCreated()
            ->json('data.gate.id');

        $this->actingAs($owner)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lot,
            'gate_id' => $gate,
            'name' => 'Cámara entrada',
            'code' => 'cam-in-1',
            'type' => 'camera',
        ])->assertCreated();

        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}/zones/{$zone}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'ZONE_HAS_SPACES');
        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}/gates/{$gate}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'GATE_IN_USE');
        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PARKING_LOT_NOT_EMPTY');

        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}/spaces/{$space}")->assertOk();
        $this->actingAs($owner)->deleteJson("/api/v1/parking-lots/{$lot}/zones/{$zone}")->assertOk();
        $this->assertDatabaseMissing('parking_zones', ['id' => $zone]);
    }
}
