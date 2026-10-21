<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Persistence\AuditLog;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_customers_are_created_with_normalized_documents_and_private_audit(): void
    {
        [$owner] = $this->createTenantOwner();

        $response = $this->actingAs($owner)->postJson('/api/v1/customers', [
            'type' => 'individual',
            'name' => '  Laura Gómez ',
            'document_type' => 'cc',
            'document_number' => '1.020.304',
            'email' => 'Laura@Example.com',
            'phone' => '+57 300 123 4567',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer.name', 'Laura Gómez')
            ->assertJsonPath('data.customer.document_type', 'CC')
            ->assertJsonPath('data.customer.email', 'laura@example.com')
            ->assertJsonPath('data.customer.status', 'active');

        $this->actingAs($owner)->postJson('/api/v1/customers', [
            'type' => 'individual',
            'name' => 'Duplicada',
            'document_type' => 'CC',
            'document_number' => '1020304',
        ])->assertUnprocessable()->assertJsonValidationErrors(['document_number'], 'error.details');

        // Same number under another document type is a different document.
        $this->createCustomer($owner, ['name' => 'Otra', 'document_type' => 'NIT', 'document_number' => '1020304']);

        $this->actingAs($owner)->postJson('/api/v1/customers', [
            'type' => 'individual',
            'name' => 'Sin tipo',
            'document_number' => '999',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_DOCUMENT');

        $this->actingAs($owner)->getJson('/api/v1/customers?search=1.020.304')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $audit = (string) json_encode(AuditLog::query()->where('action', 'customer.created')->get());
        $this->assertStringNotContainsString('laura@example.com', $audit);
        $this->assertStringNotContainsString('1020304', $audit);
        $this->assertStringNotContainsString('300 123', $audit);
    }

    public function test_individuals_can_be_affiliated_to_organizations_only(): void
    {
        [$owner] = $this->createTenantOwner();
        $business = $this->createCustomer($owner, ['type' => 'business', 'name' => 'Transportes Andinos', 'document_type' => 'NIT', 'document_number' => '900.123.456-7']);
        $person = $this->createCustomer($owner, ['business_id' => $business]);

        $this->actingAs($owner)->getJson("/api/v1/customers/{$person}")
            ->assertOk()
            ->assertJsonPath('data.customer.business.id', $business);

        $this->actingAs($owner)->getJson("/api/v1/customers/{$business}")
            ->assertOk()
            ->assertJsonPath('data.customer.members_count', 1);

        $this->actingAs($owner)->getJson("/api/v1/customers?business_id={$business}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.type_counts.business', 1);

        $this->actingAs($owner)->getJson('/api/v1/customers?organizations=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $business);

        $this->actingAs($owner)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'Pedro', 'business_id' => $person])
            ->assertUnprocessable()->assertJsonValidationErrors(['business_id'], 'error.details');

        $this->actingAs($owner)->postJson('/api/v1/customers', ['type' => 'corporate', 'name' => 'Grupo', 'business_id' => $business])
            ->assertUnprocessable()->assertJsonValidationErrors(['business_id'], 'error.details');

        $this->actingAs($owner)->patchJson("/api/v1/customers/{$business}", ['type' => 'individual', 'name' => 'Transportes Andinos'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CUSTOMER_HAS_MEMBERS');

        $this->actingAs($owner)->postJson("/api/v1/customers/{$person}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.customer.status', 'inactive');

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.status_changed', 'resource_id' => $person]);
    }

    public function test_customers_are_isolated_by_tenant(): void
    {
        [$ownerA] = $this->createTenantOwner('Empresa A');
        [$ownerB] = $this->createTenantOwner('Empresa B');
        $businessA = $this->createCustomer($ownerA, ['type' => 'business', 'name' => 'Cliente A', 'document_type' => 'NIT', 'document_number' => '800']);

        // Documents are unique per tenant, not globally.
        $this->createCustomer($ownerB, ['type' => 'business', 'name' => 'Cliente B', 'document_type' => 'NIT', 'document_number' => '800']);

        $this->actingAs($ownerB)->getJson("/api/v1/customers/{$businessA}")->assertNotFound();
        $this->actingAs($ownerB)->patchJson("/api/v1/customers/{$businessA}", ['type' => 'business', 'name' => 'Tomado'])->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/v1/customers/{$businessA}/status", ['status' => 'inactive'])->assertNotFound();
        $this->actingAs($ownerB)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'Intruso', 'business_id' => $businessA])
            ->assertUnprocessable()->assertJsonValidationErrors(['business_id'], 'error.details');

        $this->actingAs($ownerB)->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Cliente B');

        $this->assertDatabaseHas('customers', ['id' => $businessA, 'name' => 'Cliente A', 'status' => 'active']);
    }

    public function test_customer_permissions_follow_the_role(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $cashier = User::factory()->create();
        $operator = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $customer = $this->createCustomer($owner);

        $this->actingAs($cashier)->getJson('/api/v1/customers')->assertOk();
        $this->actingAs($cashier)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X'])->assertForbidden();
        $this->actingAs($cashier)->patchJson("/api/v1/customers/{$customer}", ['type' => 'individual', 'name' => 'X'])->assertForbidden();
        $this->actingAs($operator)->getJson('/api/v1/customers')->assertForbidden();
    }
}
