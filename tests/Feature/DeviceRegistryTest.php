<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Persistence\AuditLog;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DeviceRegistryTest extends TestCase
{
    use InteractsWithParking;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_a_device_is_registered_unknown_and_never_exposes_credentials(): void
    {
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);

        $response = $this->actingAs($owner)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lot,
            'name' => 'Barrera salida',
            'code' => 'barrera salida',
            'type' => 'barrier',
            'ip_address' => '192.168.1.40',
            'password' => 'secreto-de-camara',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.device.code', 'BARRERA-SALIDA')
            ->assertJsonPath('data.device.status', 'unknown')
            ->assertJsonPath('data.device.parking_lot.id', $lot)
            ->assertJsonPath('data.device.manual_transitions', ['maintenance']);

        $this->assertStringNotContainsString('secreto-de-camara', (string) $response->getContent());
        $this->assertStringNotContainsString('secreto-de-camara', (string) json_encode(AuditLog::query()->get()));

        $this->actingAs($owner)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lot,
            'name' => 'Duplicado',
            'code' => 'BARRERA-SALIDA',
            'type' => 'barrier',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code'], 'error.details');
    }

    public function test_devices_are_isolated_by_tenant_and_cannot_point_to_foreign_lots(): void
    {
        [$ownerA] = $this->createTenantOwner('Empresa A');
        [$ownerB] = $this->createTenantOwner('Empresa B');
        $lotA = $this->createLot($ownerA);
        $lotB = $this->createLot($ownerB);

        $deviceA = $this->actingAs($ownerA)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lotA,
            'name' => 'Cámara',
            'code' => 'cam-1',
            'type' => 'camera',
        ])->assertCreated()->json('data.device.id');

        // Codes are unique per tenant, not globally.
        $this->actingAs($ownerB)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lotB,
            'name' => 'Cámara',
            'code' => 'cam-1',
            'type' => 'camera',
        ])->assertCreated();

        $this->actingAs($ownerB)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lotA,
            'name' => 'Intrusa',
            'code' => 'cam-2',
            'type' => 'camera',
        ])->assertUnprocessable()->assertJsonValidationErrors(['parking_lot_id'], 'error.details');

        $this->actingAs($ownerB)->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.status_counts.unknown', 1);

        $this->actingAs($ownerB)->patchJson("/api/v1/devices/{$deviceA}", [
            'parking_lot_id' => $lotB,
            'name' => 'Robada',
            'code' => 'cam-9',
            'type' => 'camera',
        ])->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/v1/devices/{$deviceA}/status", ['status' => 'maintenance'])->assertNotFound();
        $this->actingAs($ownerB)->deleteJson("/api/v1/devices/{$deviceA}")->assertNotFound();

        $this->assertDatabaseHas('devices', ['id' => $deviceA, 'name' => 'Cámara', 'status' => 'unknown']);
    }

    public function test_a_gate_must_belong_to_the_device_lot(): void
    {
        [$owner] = $this->createTenantOwner();
        $lotA = $this->createLot($owner);
        $lotB = $this->createLot($owner, ['code' => 'norte']);
        $gateB = $this->actingAs($owner)
            ->postJson("/api/v1/parking-lots/{$lotB}/gates", ['name' => 'Entrada', 'code' => 'in', 'direction' => 'entry', 'status' => 'active'])
            ->json('data.gate.id');

        $this->actingAs($owner)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lotA,
            'gate_id' => $gateB,
            'name' => 'Lector',
            'code' => 'qr-1',
            'type' => 'access_reader',
        ])->assertUnprocessable()->assertJsonValidationErrors(['gate_id'], 'error.details');
    }

    public function test_status_changes_are_limited_to_maintenance_and_respect_permissions(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $supervisor = User::factory()->create();
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $supervisor, 'supervisor');
        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $lot = $this->createLot($owner);

        $device = $this->actingAs($owner)->postJson('/api/v1/devices', [
            'parking_lot_id' => $lot,
            'name' => 'Kiosco',
            'code' => 'kiosk-1',
            'type' => 'kiosk',
        ])->json('data.device.id');

        $this->actingAs($owner)->postJson("/api/v1/devices/{$device}/status", ['status' => 'maintenance'])
            ->assertOk()
            ->assertJsonPath('data.device.status', 'maintenance')
            ->assertJsonPath('data.device.manual_transitions', ['unknown']);

        $this->actingAs($owner)->postJson("/api/v1/devices/{$device}/status", ['status' => 'online'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DEVICE_TRANSITION_NOT_ALLOWED');

        $this->actingAs($supervisor)->getJson("/api/v1/devices?parking_lot_id={$lot}&status=maintenance")
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
        $this->actingAs($supervisor)->postJson("/api/v1/devices/{$device}/status", ['status' => 'unknown'])->assertForbidden();
        $this->actingAs($cashier)->getJson('/api/v1/devices')->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'device.status_changed',
            'resource_id' => $device,
        ]);
    }
}
