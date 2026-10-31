<?php

declare(strict_types=1);

use App\Modules\Memberships\Presentation\Http\Controllers\MembershipController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('membership-plans')
    ->whereUlid('plan')
    ->group(function (): void {
        Route::get('/', [MembershipController::class, 'plans'])
            ->middleware('permission:memberships.view')
            ->name('membership-plans.index');
        Route::post('/', [MembershipController::class, 'storePlan'])
            ->middleware('permission:memberships.manage')
            ->name('membership-plans.store');
        Route::patch('{plan}', [MembershipController::class, 'updatePlan'])
            ->middleware('permission:memberships.manage')
            ->name('membership-plans.update');
        Route::post('{plan}/status', [MembershipController::class, 'planStatus'])
            ->middleware('permission:memberships.manage')
            ->name('membership-plans.status');
    });

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('memberships')
    ->whereUlid(['membership', 'vehicle'])
    ->group(function (): void {
        Route::get('/', [MembershipController::class, 'index'])
            ->middleware('permission:memberships.view')
            ->name('memberships.index');
        Route::post('/', [MembershipController::class, 'store'])
            ->middleware(['permission:memberships.manage', 'throttle:60,1'])
            ->name('memberships.store');
        Route::get('{membership}', [MembershipController::class, 'show'])
            ->middleware('permission:memberships.view')
            ->name('memberships.show');
        Route::post('{membership}/vehicles', [MembershipController::class, 'addVehicle'])
            ->middleware('permission:memberships.manage')
            ->name('memberships.vehicles.store');
        Route::delete('{membership}/vehicles/{vehicle}', [MembershipController::class, 'removeVehicle'])
            ->middleware('permission:memberships.manage')
            ->name('memberships.vehicles.destroy');
        Route::post('{membership}/renew', [MembershipController::class, 'renew'])
            ->middleware('permission:memberships.manage')
            ->name('memberships.renew');
        Route::post('{membership}/suspend', [MembershipController::class, 'suspend'])
            ->middleware('permission:memberships.manage')
            ->name('memberships.suspend');
        Route::post('{membership}/reactivate', [MembershipController::class, 'reactivate'])
            ->middleware('permission:memberships.manage')
            ->name('memberships.reactivate');
        Route::post('{membership}/cancel', [MembershipController::class, 'cancel'])
            ->middleware('permission:memberships.manage')
            ->name('memberships.cancel');
        Route::post('{membership}/auto-renew', [MembershipController::class, 'autoRenew'])
            ->middleware('permission:memberships.manage')
            ->name('memberships.auto-renew');
    });
