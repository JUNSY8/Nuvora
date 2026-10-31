<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Version 0.8 adds memberships.view (plans, memberships and periods) and
 * memberships.manage (sell, renew, suspend and cancel them, and edit
 * plans). New tenants receive them from SystemRoles; this backfills the
 * system roles of existing tenants.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $grants = [
        'tenant_owner' => ['memberships.view', 'memberships.manage'],
        'tenant_administrator' => ['memberships.view', 'memberships.manage'],
        'supervisor' => ['memberships.view', 'memberships.manage'],
        'cashier' => ['memberships.view', 'memberships.manage'],
        'parking_operator' => ['memberships.view'],
        'accountant' => ['memberships.view'],
        'report_viewer' => ['memberships.view'],
    ];

    public function up(): void
    {
        foreach ($this->grants as $roleKey => $permissions) {
            $roleIds = DB::table('roles')
                ->where('key', $roleKey)
                ->where('is_system', true)
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($permissions as $permission) {
                    $exists = DB::table('role_permissions')
                        ->where('role_id', $roleId)
                        ->where('permission', $permission)
                        ->exists();

                    if (! $exists) {
                        DB::table('role_permissions')->insert([
                            'id' => strtolower((string) Str::ulid()),
                            'role_id' => $roleId,
                            'permission' => $permission,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission', ['memberships.view', 'memberships.manage'])
            ->delete();
    }
};
