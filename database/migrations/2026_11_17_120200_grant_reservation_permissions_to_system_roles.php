<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Version 0.7 adds reservations.view (reservations and availability) and
 * reservations.manage (create, confirm and cancel them). New tenants
 * receive them from SystemRoles; this backfills the system roles of existing
 * tenants.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $grants = [
        'tenant_owner' => ['reservations.view', 'reservations.manage'],
        'tenant_administrator' => ['reservations.view', 'reservations.manage'],
        'supervisor' => ['reservations.view', 'reservations.manage'],
        'cashier' => ['reservations.view', 'reservations.manage'],
        'parking_operator' => ['reservations.view'],
        'report_viewer' => ['reservations.view'],
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
            ->whereIn('permission', ['reservations.view', 'reservations.manage'])
            ->delete();
    }
};
