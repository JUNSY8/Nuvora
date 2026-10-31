<?php

declare(strict_types=1);

use App\Modules\Tariffs\Presentation\Http\Controllers\DiscountController;
use App\Modules\Tariffs\Presentation\Http\Controllers\TariffController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('tariffs')
    ->whereUlid('tariff')
    ->group(function (): void {
        Route::get('/', [TariffController::class, 'index'])
            ->middleware('permission:tariffs.view')
            ->name('tariffs.index');
        Route::post('/', [TariffController::class, 'store'])
            ->middleware('permission:tariffs.manage')
            ->name('tariffs.store');
        Route::post('simulate', [TariffController::class, 'simulate'])
            ->middleware(['permission:tariffs.view', 'throttle:60,1'])
            ->name('tariffs.simulate');
        Route::patch('{tariff}', [TariffController::class, 'update'])
            ->middleware('permission:tariffs.manage')
            ->name('tariffs.update');
        Route::post('{tariff}/status', [TariffController::class, 'status'])
            ->middleware('permission:tariffs.manage')
            ->name('tariffs.status');
        Route::get('{tariff}/versions', [TariffController::class, 'versions'])
            ->middleware('permission:tariffs.view')
            ->name('tariffs.versions');
    });

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('discounts')
    ->whereUlid('discount')
    ->group(function (): void {
        Route::get('/', [DiscountController::class, 'index'])
            ->middleware('permission:tariffs.view')
            ->name('discounts.index');
        Route::post('/', [DiscountController::class, 'store'])
            ->middleware('permission:tariffs.manage')
            ->name('discounts.store');
        Route::patch('{discount}', [DiscountController::class, 'update'])
            ->middleware('permission:tariffs.manage')
            ->name('discounts.update');
        Route::post('{discount}/status', [DiscountController::class, 'status'])
            ->middleware('permission:tariffs.manage')
            ->name('discounts.status');
    });
