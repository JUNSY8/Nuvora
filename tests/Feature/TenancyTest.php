<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Persistence\AuditLog;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Tenancy\Application\CreateTenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_unverified_user_cannot_create_a_tenant(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Norte',
            'timezone' => 'America/Bogota',
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_creating_a_tenant_makes_the_user_owner_and_ignores_a_client_tenant_id(): void
    {
        [$otherOwner, $otherTenant] = $this->createTenantOwner('Otra empresa');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Parqueadero Norte',
            'timezone' => 'America/Bogota',
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.current_tenant.name', 'Parqueadero Norte')
            ->assertJsonPath('data.current_tenant.roles.0', 'tenant_owner');

        $createdId = $response->json('data.current_tenant.id');
        $this->assertNotSame($otherTenant->id, $createdId);
        $this->assertTrue(collect($response->json('data.current_tenant.permissions'))->contains('users.manage'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.created',
            'tenant_id' => $createdId,
            'actor_user_id' => $user->id,
        ]);

        $audit = AuditLog::query()->where('action', 'tenant.created')->where('tenant_id', $createdId)->first();
        $this->assertIsArray($audit?->properties);
        $this->assertArrayNotHasKey('password', $audit->properties);
        $this->assertNotSame($otherOwner->id, $user->id);
    }

    public function test_a_member_cannot_read_or_change_another_tenant(): void
    {
        [$ownerA, $tenantA] = $this->createTenantOwner('Empresa A');
        [$ownerB, $tenantB] = $this->createTenantOwner('Empresa B');

        $membershipB = TenantMembership::query()->where('tenant_id', $tenantB->id)->firstOrFail();

        $this->actingAs($ownerA)
            ->getJson('/api/v1/tenant/members')
            ->assertOk()
            ->assertJsonPath('data.members.0.user.id', $ownerA->id)
            ->assertJsonMissing(['id' => $ownerB->id]);

        $this->actingAs($ownerA)
            ->withHeader('X-Tenant-Id', $tenantB->id)
            ->getJson('/api/v1/tenant/members')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->actingAs($ownerA)
            ->patchJson('/api/v1/tenant/members/'.$membershipB->id, [
                'status' => 'suspended',
            ])->assertNotFound();

        $this->assertSame('active', $membershipB->fresh()->status->value);
        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    public function test_permissions_block_a_cashier_and_stop_owner_escalation(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $cashier = User::factory()->create();
        $administrator = User::factory()->create();
        $candidate = User::factory()->create();

        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $this->addMember($owner, $tenant, $administrator, 'tenant_administrator');

        $this->actingAs($cashier)
            ->patchJson('/api/v1/tenant', [
                'name' => 'Tomada',
                'timezone' => 'America/Bogota',
            ])->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($administrator)
            ->postJson('/api/v1/tenant/members', [
                'email' => $candidate->email,
                'roles' => ['tenant_owner'],
            ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'MEMBERSHIP_RULE');

        $this->actingAs($owner)
            ->patchJson('/api/v1/tenant', [
                'name' => 'Parqueadero Central Actualizado',
                'timezone' => 'America/Bogota',
            ])->assertOk()
            ->assertJsonPath('data.tenant.name', 'Parqueadero Central Actualizado');
    }

    public function test_the_last_owner_cannot_be_removed_and_a_suspended_member_loses_access(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');

        $ownerMembership = TenantMembership::query()->where('user_id', $owner->id)->firstOrFail();
        $cashierMembership = TenantMembership::query()->where('user_id', $cashier->id)->firstOrFail();

        $this->actingAs($owner)
            ->putJson('/api/v1/tenant/members/'.$ownerMembership->id.'/roles', [
                'roles' => ['cashier'],
            ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'MEMBERSHIP_RULE');

        $this->actingAs($owner)
            ->patchJson('/api/v1/tenant/members/'.$ownerMembership->id, [
                'status' => 'suspended',
            ])->assertUnprocessable();

        $this->actingAs($owner)
            ->patchJson('/api/v1/tenant/members/'.$cashierMembership->id, [
                'status' => 'suspended',
            ])->assertOk();

        $this->actingAs($cashier)
            ->getJson('/api/v1/tenant/members')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');
    }

    public function test_a_user_with_two_tenants_must_choose_one_and_only_sees_that_tenant(): void
    {
        [$owner, $first] = $this->createTenantOwner('Primera');
        $second = app(CreateTenant::class)->handle($owner, 'Segunda', 'America/Lima');

        $this->actingAs($owner)
            ->getJson('/api/v1/tenant/members')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');

        $this->actingAs($owner)
            ->withHeader('X-Tenant-Id', $second->id)
            ->getJson('/api/v1/tenant')
            ->assertOk()
            ->assertJsonPath('data.tenant.name', 'Segunda');

        $this->actingAs($owner)
            ->postJson('/api/v1/tenant-context', [
                'tenant_id' => $first->id,
            ])->assertOk()
            ->assertJsonPath('data.current_tenant.id', $first->id);

        $this->actingAs($owner)
            ->postJson('/api/v1/tenant-context', [
                'tenant_id' => (string) Str::ulid(),
            ])->assertNotFound();
    }

    public function test_a_suspended_tenant_is_not_selectable(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        $tenant->status = TenantStatus::Suspended;
        $tenant->save();

        $this->actingAs($owner)
            ->getJson('/api/v1/tenant')
            ->assertStatus(409);
    }

    public function test_custom_roles_only_accept_catalog_permissions(): void
    {
        [$owner] = $this->createTenantOwner();

        $this->actingAs($owner)->postJson('/api/v1/tenant/roles', [
            'name' => 'Portería',
            'permissions' => ['parking.view', 'not.a.permission'],
        ])->assertUnprocessable();

        $this->actingAs($owner)->postJson('/api/v1/tenant/roles', [
            'name' => 'Portería',
            'permissions' => ['parking.view'],
        ])->assertCreated()
            ->assertJsonPath('data.role.permissions.0', 'parking.view')
            ->assertJsonPath('data.role.is_system', false);
    }
}
