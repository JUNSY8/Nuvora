<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Version 0.9 adds payments.reconcile (close the cash of any cashier). The
 * other payment permissions existed in the catalog before payments did;
 * this backfills all of them so existing system roles match SystemRoles.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $grants = [
        'tenant_owner' => ['payments.view', 'payments.create', 'payments.refund', 'payments.reconcile'],
        'tenant_administrator' => ['payments.view', 'payments.create', 'payments.reconcile'],
        'supervisor' => ['payments.view', 'payments.reconcile'],
        'cashier' => ['payments.view', 'payments.create'],
        'accountant' => ['payments.view'],
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
        DB::table('role_permissions')->where('permission', 'payments.reconcile')->delete();
    }
};
