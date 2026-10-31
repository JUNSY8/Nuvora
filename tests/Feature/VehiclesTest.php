<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class VehiclesTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_plates_are_normalized_unique_per_country_and_described(): void
    {
        [$owner] = $this->createTenantOwner();

        $this->actingAs($owner)->postJson('/api/v1/vehicles', [
            'plate' => 'abc-123',
            'country' => 'co',
            'type' => 'car',
            'brand' => 'Mazda',
            'year' => 2021,
        ])->assertCreated()
            ->assertJsonPath('data.vehicle.plate', 'ABC123')
            ->assertJsonPath('data.vehicle.country', 'CO')
            ->assertJsonPath('data.vehicle.plate_format.key', 'vehicle')
            ->assertJsonPath('data.vehicle.status', 'active');

        $this->actingAs($owner)->postJson('/api/v1/vehicles', ['plate' => 'ABC 123', 'country' => 'CO', 'type' => 'car'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plate'], 'error.details');

        // The same characters issued by another country are another vehicle.
        $this->createVehicle($owner, 'ABC123', ['country' => 'EC']);

        $this->actingAs($owner)->postJson('/api/v1/vehicles', ['plate' => 'AB#1', 'country' => 'CO', 'type' => 'car'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_PLATE');

        $this->actingAs($owner)->getJson('/api/v1/vehicles/plate-check?plate=abc%20123&country=CO')
            ->assertOk()
            ->assertJsonPath('data.plate', 'ABC123')
            ->assertJsonPath('data.existing.plate', 'ABC123')
            ->assertJsonPath('data.suggested_type', 'car');

        $this->actingAs($owner)->getJson('/api/v1/vehicles/plate-check?plate=xyz12d&country=CO')
            ->assertOk()
            ->assertJsonPath('data.existing', null)
            ->assertJsonPath('data.format.key', 'motorcycle')
            ->assertJsonPath('data.suggested_type', 'motorcycle');

        $this->actingAs($owner)->getJson('/api/v1/vehicles?search=abc-1')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.status_counts.active', 2);
    }

    public function test_customer_links_enforce_a_single_owner_and_keep_history(): void
    {
        [$owner] = $this->createTenantOwner();
        $laura = $this->createCustomer($owner, ['name' => 'Laura']);
        $pedro = $this->createCustomer($owner, ['name' => 'Pedro']);
        $vehicle = $this->createVehicle($owner, 'DEF456', ['customer_id' => $laura, 'relationship' => 'owner']);

        $this->actingAs($owner)->getJson("/api/v1/vehicles/{$vehicle}")
            ->assertOk()
            ->assertJsonCount(1, 'data.vehicle.links')
            ->assertJsonPath('data.vehicle.links.0.customer.name', 'Laura')
            ->assertJsonPath('data.vehicle.links.0.relationship', 'owner');

        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$vehicle}/customers", ['customer_id' => $pedro, 'relationship' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VEHICLE_HAS_OWNER');

        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$vehicle}/customers", ['customer_id' => $laura, 'relationship' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'ALREADY_LINKED');

        $driverLink = $this->actingAs($owner)->postJson("/api/v1/vehicles/{$vehicle}/customers", ['customer_id' => $pedro, 'relationship' => 'driver'])
            ->assertCreated()
            ->json('data.link.id');

        // Promoting the driver to owner transfers ownership on the same link.
        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$vehicle}/customers", [
            'customer_id' => $pedro,
            'relationship' => 'owner',
            'transfer_ownership' => true,
        ])->assertCreated()->assertJsonPath('data.link.id', $driverLink);

        $this->actingAs($owner)->getJson("/api/v1/vehicles?customer_id={$laura}")->assertOk()->assertJsonCount(0, 'data.items');
        $this->actingAs($owner)->getJson("/api/v1/vehicles?customer_id={$pedro}")->assertOk()->assertJsonCount(1, 'data.items');

        $this->actingAs($owner)->deleteJson("/api/v1/vehicles/{$vehicle}/customers/{$driverLink}")->assertOk();
        $this->actingAs($owner)->deleteJson("/api/v1/vehicles/{$vehicle}/customers/{$driverLink}")->assertNotFound();

        $history = $this->actingAs($owner)->getJson("/api/v1/vehicles/{$vehicle}/history")->assertOk();
        $this->assertSame(
            ['customer_unlinked', 'relationship_changed', 'customer_unlinked', 'customer_linked', 'customer_linked', 'registered'],
            array_column($history->json('data.items'), 'type'),
        );
        $this->assertSame('ownership_transferred', $history->json('data.items.2.data.reason'));
        $this->assertSame($owner->name, $history->json('data.items.0.actor.name'));
        $this->assertDatabaseCount('customer_vehicles', 2);

        $this->actingAs($owner)->postJson("/api/v1/customers/{$laura}/status", ['status' => 'inactive'])->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$vehicle}/customers", ['customer_id' => $laura, 'relationship' => 'driver'])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id'], 'error.details');
    }

    public function test_status_and_plate_changes_are_recorded_in_the_timeline(): void
    {
        [$owner] = $this->createTenantOwner();
        $vehicle = $this->createVehicle($owner, 'GHI789');

        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$vehicle}/status", ['status' => 'blocked'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');

        $this->actingAs($owner)->postJson("/api/v1/vehicles/{$vehicle}/status", ['status' => 'blocked', 'reason' => 'Deuda pendiente'])
            ->assertOk()
            ->assertJsonPath('data.vehicle.status', 'blocked')
            ->assertJsonPath('data.vehicle.status_reason', 'Deuda pendiente');

        $this->actingAs($owner)->patchJson("/api/v1/vehicles/{$vehicle}", [
            'plate' => 'ghi-788',
            'country' => 'CO',
            'type' => 'van',
            'color' => 'Blanco',
        ])->assertOk()->assertJsonPath('data.vehicle.plate', 'GHI788');

        $history = $this->actingAs($owner)->getJson("/api/v1/vehicles/{$vehicle}/history")->assertOk()->json('data.items');

        $this->assertSame(['updated', 'plate_changed', 'status_changed', 'registered'], array_column($history, 'type'));
        $this->assertSame(['from' => 'GHI789', 'to' => 'GHI788', 'from_country' => 'CO', 'to_country' => 'CO'], $history[1]['data']);
        $this->assertSame(['from' => 'car', 'to' => 'van'], $history[0]['data']['changes']['type']);
        $this->assertSame('Deuda pendiente', $history[2]['data']['reason']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vehicle.status_changed', 'resource_id' => $vehicle]);
    }

    public function test_vehicles_are_isolated_by_tenant(): void
    {
        [$ownerA] = $this->createTenantOwner('Empresa A');
        [$ownerB] = $this->createTenantOwner('Empresa B');
        $customerA = $this->createCustomer($ownerA);
        $vehicleA = $this->createVehicle($ownerA, 'JKL012', ['customer_id' => $customerA]);
        $vehicleB = $this->createVehicle($ownerB, 'JKL012');

        $this->actingAs($ownerB)->getJson("/api/v1/vehicles/{$vehicleA}")->assertNotFound();
        $this->actingAs($ownerB)->getJson("/api/v1/vehicles/{$vehicleA}/history")->assertNotFound();
        $this->actingAs($ownerB)->patchJson("/api/v1/vehicles/{$vehicleA}", ['plate' => 'XXX000', 'country' => 'CO', 'type' => 'car'])->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/v1/vehicles/{$vehicleA}/status", ['status' => 'inactive'])->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/v1/vehicles/{$vehicleB}/customers", ['customer_id' => $customerA, 'relationship' => 'driver'])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id'], 'error.details');
        $this->actingAs($ownerB)->postJson('/api/v1/vehicles', ['plate' => 'MNO345', 'country' => 'CO', 'type' => 'car', 'customer_id' => $customerA])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id'], 'error.details');

        $this->actingAs($ownerB)->getJson('/api/v1/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $vehicleB);

        $this->actingAs($ownerB)->getJson('/api/v1/vehicles/plate-check?plate=JKL012&country=CO')
            ->assertOk()
            ->assertJsonPath('data.existing.id', $vehicleB);

        $this->assertDatabaseHas('vehicles', ['id' => $vehicleA, 'plate' => 'JKL012', 'status' => 'active']);
    }

    public function test_vehicle_permissions_follow_the_role(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $operator = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $vehicle = $this->createVehicle($owner, 'PQR678');

        $this->actingAs($operator)->getJson('/api/v1/vehicles')->assertOk();
        $this->actingAs($operator)->getJson("/api/v1/vehicles/{$vehicle}/history")->assertOk();
        $this->actingAs($operator)->postJson('/api/v1/vehicles', ['plate' => 'STU901', 'country' => 'CO', 'type' => 'car'])->assertForbidden();
        $this->actingAs($operator)->postJson("/api/v1/vehicles/{$vehicle}/status", ['status' => 'inactive'])->assertForbidden();
    }
}
