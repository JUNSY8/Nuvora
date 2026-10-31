<?php

declare(strict_types=1);

use App\Modules\Customers\Presentation\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('customers')
    ->whereUlid('customer')
    ->group(function (): void {
        Route::get('/', [CustomerController::class, 'index'])
            ->middleware('permission:customers.view')
            ->name('customers.index');
        Route::post('/', [CustomerController::class, 'store'])
            ->middleware('permission:customers.create')
            ->name('customers.store');
        Route::get('{customer}', [CustomerController::class, 'show'])
            ->middleware('permission:customers.view')
            ->name('customers.show');
        Route::patch('{customer}', [CustomerController::class, 'update'])
            ->middleware('permission:customers.update')
            ->name('customers.update');
        Route::post('{customer}/status', [CustomerController::class, 'status'])
            ->middleware('permission:customers.update')
            ->name('customers.status');
    });
