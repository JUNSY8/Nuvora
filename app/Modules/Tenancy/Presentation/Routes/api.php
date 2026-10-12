<?php

declare(strict_types=1);

use App\Modules\Tenancy\Presentation\Http\Controllers\TenantContextController;
use App\Modules\Tenancy\Presentation\Http\Controllers\TenantController;
use App\Modules\Tenancy\Presentation\Http\Controllers\TenantMemberController;
use App\Modules\Tenancy\Presentation\Http\Controllers\TenantRoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context'])->group(function (): void {
    Route::post('tenants', [TenantController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('tenants.store');

    Route::post('tenant-context', [TenantContextController::class, 'store'])
        ->name('tenant-context.store');

    Route::middleware('tenant.required')->group(function (): void {
        Route::get('tenant', [TenantController::class, 'show'])->name('tenant.show');
        Route::patch('tenant', [TenantController::class, 'update'])
            ->middleware('permission:tenants.update')
            ->name('tenant.update');

        Route::get('tenant/members', [TenantMemberController::class, 'index'])
            ->middleware('permission:users.view')
            ->name('tenant.members.index');
        Route::post('tenant/members', [TenantMemberController::class, 'store'])
            ->middleware('permission:users.manage')
            ->name('tenant.members.store');
        Route::patch('tenant/members/{membership}', [TenantMemberController::class, 'update'])
            ->middleware('permission:users.manage')
            ->name('tenant.members.update');
        Route::put('tenant/members/{membership}/roles', [TenantMemberController::class, 'syncRoles'])
            ->middleware('permission:roles.manage')
            ->name('tenant.members.roles');

        Route::get('tenant/roles', [TenantRoleController::class, 'index'])
            ->middleware('permission:roles.view')
            ->name('tenant.roles.index');
        Route::post('tenant/roles', [TenantRoleController::class, 'store'])
            ->middleware('permission:roles.manage')
            ->name('tenant.roles.store');
    });
});
