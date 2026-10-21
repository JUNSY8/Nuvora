<?php

declare(strict_types=1);

use App\Modules\Devices\Presentation\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('devices')
    ->whereUlid('device')
    ->group(function (): void {
        Route::get('/', [DeviceController::class, 'index'])
            ->middleware('permission:devices.view')
            ->name('devices.index');
        Route::post('/', [DeviceController::class, 'store'])
            ->middleware('permission:devices.manage')
            ->name('devices.store');
        Route::patch('{device}', [DeviceController::class, 'update'])
            ->middleware('permission:devices.manage')
            ->name('devices.update');
        Route::post('{device}/status', [DeviceController::class, 'status'])
            ->middleware('permission:devices.manage')
            ->name('devices.status');
        Route::delete('{device}', [DeviceController::class, 'destroy'])
            ->middleware('permission:devices.manage')
            ->name('devices.destroy');
    });
