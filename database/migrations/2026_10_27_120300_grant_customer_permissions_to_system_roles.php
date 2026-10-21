<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Version 0.4 adds customers.view, customers.create and customers.update to
 * the catalog. New tenants receive them from SystemRoles; this backfills the
 * system roles of existing tenants with the grants of this release.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $grants = [
        'tenant_owner' => ['customers.view', 'customers.create', 'customers.update'],
        'tenant_administrator' => ['customers.view', 'customers.create', 'customers.update'],
        'supervisor' => ['customers.view'],
        'cashier' => ['customers.view'],
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
            ->whereIn('permission', ['customers.view', 'customers.create', 'customers.update'])
            ->delete();
    }
};
