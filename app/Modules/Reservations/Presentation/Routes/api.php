<?php

declare(strict_types=1);

use App\Modules\Reservations\Presentation\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('reservations')
    ->whereUlid('reservation')
    ->group(function (): void {
        Route::get('/', [ReservationController::class, 'index'])
            ->middleware('permission:reservations.view')
            ->name('reservations.index');
        Route::post('/', [ReservationController::class, 'store'])
            ->middleware(['permission:reservations.manage', 'throttle:60,1'])
            ->name('reservations.store');
        Route::get('availability', [ReservationController::class, 'availability'])
            ->middleware(['permission:reservations.view', 'throttle:120,1'])
            ->name('reservations.availability');
        Route::get('policy', [ReservationController::class, 'policy'])
            ->middleware('permission:reservations.view')
            ->name('reservations.policy');
        Route::put('policy', [ReservationController::class, 'updatePolicy'])
            ->middleware('permission:parking.update')
            ->name('reservations.policy.update');
        Route::get('{reservation}', [ReservationController::class, 'show'])
            ->middleware('permission:reservations.view')
            ->name('reservations.show');
        Route::post('{reservation}/confirm', [ReservationController::class, 'confirm'])
            ->middleware('permission:reservations.manage')
            ->name('reservations.confirm');
        Route::post('{reservation}/cancel', [ReservationController::class, 'cancel'])
            ->middleware('permission:reservations.manage')
            ->name('reservations.cancel');
    });
