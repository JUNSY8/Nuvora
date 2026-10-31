<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Version 0.5 adds sessions.view (operation screens and history) and
 * sessions.cancel (voiding a stay entered by mistake). New tenants receive
 * them from SystemRoles; this backfills the system roles of existing tenants.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $grants = [
        'tenant_owner' => ['sessions.view', 'sessions.cancel'],
        'tenant_administrator' => ['sessions.view', 'sessions.cancel'],
        'supervisor' => ['sessions.view', 'sessions.cancel'],
        'cashier' => ['sessions.view'],
        'parking_operator' => ['sessions.view'],
        'report_viewer' => ['sessions.view'],
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
            ->whereIn('permission', ['sessions.view', 'sessions.cancel'])
            ->delete();
    }
};
