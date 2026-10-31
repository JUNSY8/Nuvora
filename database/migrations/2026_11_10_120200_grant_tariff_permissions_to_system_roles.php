<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Version 0.6 adds tariffs.view (tariffs, discounts and the simulator),
 * tariffs.manage (configure them) and sessions.discount (apply a discount at
 * the exit). New tenants receive them from SystemRoles; this backfills the
 * system roles of existing tenants.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $grants = [
        'tenant_owner' => ['tariffs.view', 'tariffs.manage', 'sessions.discount'],
        'tenant_administrator' => ['tariffs.view', 'tariffs.manage', 'sessions.discount'],
        'supervisor' => ['tariffs.view', 'sessions.discount'],
        'cashier' => ['tariffs.view', 'sessions.discount'],
        'parking_operator' => ['tariffs.view'],
        'accountant' => ['tariffs.view'],
        'report_viewer' => ['tariffs.view'],
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
            ->whereIn('permission', ['tariffs.view', 'tariffs.manage', 'sessions.discount'])
            ->delete();
    }
};
