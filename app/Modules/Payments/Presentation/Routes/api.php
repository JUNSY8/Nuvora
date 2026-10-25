<?php

declare(strict_types=1);

use App\Modules\Payments\Presentation\Http\Controllers\PaymentController;
use App\Modules\Payments\Presentation\Http\Controllers\ReconciliationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('payments')
    ->whereUlid('payment')
    ->group(function (): void {
        Route::get('/', [PaymentController::class, 'index'])
            ->middleware('permission:payments.view')
            ->name('payments.index');
        Route::post('/', [PaymentController::class, 'store'])
            ->middleware(['permission:payments.create', 'throttle:120,1'])
            ->name('payments.store');
        Route::get('{payment}', [PaymentController::class, 'show'])
            ->middleware('permission:payments.view')
            ->name('payments.show');
        Route::get('{payment}/receipt', [PaymentController::class, 'receipt'])
            ->middleware('permission:payments.view')
            ->name('payments.receipt');
        Route::post('{payment}/refunds', [PaymentController::class, 'refund'])
            ->middleware(['permission:payments.refund', 'throttle:60,1'])
            ->name('payments.refunds.store');
    });

// Closing one's own cash takes payments.create and anyone else's payments.reconcile; the use case decides.
Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('payment-reconciliations')
    ->group(function (): void {
        Route::get('/', [ReconciliationController::class, 'index'])
            ->middleware('permission:payments.view')
            ->name('payment-reconciliations.index');
        Route::get('preview', [ReconciliationController::class, 'preview'])
            ->name('payment-reconciliations.preview');
        Route::post('/', [ReconciliationController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('payment-reconciliations.store');
    });
