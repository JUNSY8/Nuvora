<?php

declare(strict_types=1);

use App\Modules\Vehicles\Presentation\Http\Controllers\VehicleController;
use App\Modules\Vehicles\Presentation\Http\Controllers\VehicleRelationsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('vehicles')
    ->whereUlid(['vehicle', 'link'])
    ->group(function (): void {
        Route::get('/', [VehicleController::class, 'index'])
            ->middleware('permission:vehicles.view')
            ->name('vehicles.index');
        Route::get('plate-check', [VehicleController::class, 'plateCheck'])
            ->middleware(['permission:vehicles.view', 'throttle:120,1'])
            ->name('vehicles.plate-check');
        Route::post('/', [VehicleController::class, 'store'])
            ->middleware('permission:vehicles.create')
            ->name('vehicles.store');
        Route::get('{vehicle}', [VehicleController::class, 'show'])
            ->middleware('permission:vehicles.view')
            ->name('vehicles.show');
        Route::patch('{vehicle}', [VehicleController::class, 'update'])
            ->middleware('permission:vehicles.update')
            ->name('vehicles.update');
        Route::post('{vehicle}/status', [VehicleController::class, 'status'])
            ->middleware('permission:vehicles.update')
            ->name('vehicles.status');
        Route::get('{vehicle}/history', [VehicleRelationsController::class, 'history'])
            ->middleware('permission:vehicles.view')
            ->name('vehicles.history');
        Route::post('{vehicle}/customers', [VehicleRelationsController::class, 'link'])
            ->middleware('permission:vehicles.update')
            ->name('vehicles.customers.link');
        Route::delete('{vehicle}/customers/{link}', [VehicleRelationsController::class, 'unlink'])
            ->middleware('permission:vehicles.update')
            ->name('vehicles.customers.unlink');
    });
