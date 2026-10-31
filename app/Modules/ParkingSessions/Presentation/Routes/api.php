<?php

declare(strict_types=1);

use App\Modules\ParkingSessions\Presentation\Http\Controllers\ParkingSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('parking-sessions')
    ->whereUlid(['session'])
    ->group(function (): void {
        Route::get('/', [ParkingSessionController::class, 'index'])
            ->middleware('permission:sessions.view')
            ->name('parking-sessions.index');
        Route::get('summary', [ParkingSessionController::class, 'summary'])
            ->middleware('permission:sessions.view')
            ->name('parking-sessions.summary');
        Route::post('entries', [ParkingSessionController::class, 'entry'])
            ->middleware(['permission:sessions.create', 'throttle:120,1'])
            ->name('parking-sessions.entry');
        Route::get('{session}', [ParkingSessionController::class, 'show'])
            ->middleware('permission:sessions.view')
            ->name('parking-sessions.show');
        Route::get('{session}/quote', [ParkingSessionController::class, 'quote'])
            ->middleware(['permission:sessions.view', 'throttle:120,1'])
            ->name('parking-sessions.quote');
        Route::post('{session}/exit', [ParkingSessionController::class, 'exit'])
            ->middleware(['permission:sessions.close', 'throttle:120,1'])
            ->name('parking-sessions.exit');
        Route::post('{session}/cancel', [ParkingSessionController::class, 'cancel'])
            ->middleware('permission:sessions.cancel')
            ->name('parking-sessions.cancel');
        Route::post('{session}/space', [ParkingSessionController::class, 'move'])
            ->middleware('permission:sessions.create')
            ->name('parking-sessions.space');
    });
