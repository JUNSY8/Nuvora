<?php

declare(strict_types=1);

use App\Modules\Payments\Presentation\Http\Controllers\OnlinePaymentController;
use App\Modules\Payments\Presentation\Http\Controllers\PaymentController;
use App\Modules\Payments\Presentation\Http\Controllers\ProviderAccountController;
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
        Route::post('checkouts', [OnlinePaymentController::class, 'checkout'])
            ->middleware(['permission:payments.create', 'throttle:60,1'])
            ->name('payments.checkouts.store');
        Route::post('{payment}/cancel', [OnlinePaymentController::class, 'cancel'])
            ->middleware(['permission:payments.create', 'throttle:60,1'])
            ->name('payments.cancel');
        Route::post('{payment}/sync', [OnlinePaymentController::class, 'sync'])
            ->middleware(['permission:payments.view', 'throttle:30,1'])
            ->name('payments.sync');
    });

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('payment-providers')
    ->whereUlid('account')
    ->group(function (): void {
        Route::get('/', [ProviderAccountController::class, 'index'])
            ->middleware('permission:payments.configure')
            ->name('payment-providers.index');
        Route::get('options', [ProviderAccountController::class, 'options'])
            ->middleware('permission:payments.create')
            ->name('payment-providers.options');
        Route::post('/', [ProviderAccountController::class, 'store'])
            ->middleware(['permission:payments.configure', 'throttle:30,1'])
            ->name('payment-providers.store');
        Route::patch('{account}', [ProviderAccountController::class, 'update'])
            ->middleware(['permission:payments.configure', 'throttle:30,1'])
            ->name('payment-providers.update');
        Route::post('{account}/reconcile', [ProviderAccountController::class, 'reconcile'])
            ->middleware(['permission:payments.reconcile', 'throttle:10,1'])
            ->name('payment-providers.reconcile');
    });

// The payment link page: anyone with the link may see it; the token is the capability.
Route::get('public/checkouts/{token}', [OnlinePaymentController::class, 'publicCheckout'])
    ->middleware('throttle:60,1')
    ->name('public.checkouts.show');

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
