<?php

declare(strict_types=1);

use App\Modules\Parking\Presentation\Http\Controllers\ParkingGateController;
use App\Modules\Parking\Presentation\Http\Controllers\ParkingLotController;
use App\Modules\Parking\Presentation\Http\Controllers\ParkingSpaceController;
use App\Modules\Parking\Presentation\Http\Controllers\ParkingZoneController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required'])
    ->prefix('parking-lots')
    ->whereUlid(['lot', 'zone', 'space', 'gate'])
    ->group(function (): void {
        Route::get('/', [ParkingLotController::class, 'index'])
            ->middleware('permission:parking.view')
            ->name('parking-lots.index');
        Route::post('/', [ParkingLotController::class, 'store'])
            ->middleware('permission:parking.create')
            ->name('parking-lots.store');
        Route::get('{lot}', [ParkingLotController::class, 'show'])
            ->middleware('permission:parking.view')
            ->name('parking-lots.show');
        Route::patch('{lot}', [ParkingLotController::class, 'update'])
            ->middleware('permission:parking.update')
            ->name('parking-lots.update');
        Route::delete('{lot}', [ParkingLotController::class, 'destroy'])
            ->middleware('permission:parking.delete')
            ->name('parking-lots.destroy');
        Route::put('{lot}/operating-hours', [ParkingLotController::class, 'hours'])
            ->middleware('permission:parking.update')
            ->name('parking-lots.hours');

        Route::get('{lot}/zones', [ParkingZoneController::class, 'index'])
            ->middleware('permission:parking.view')
            ->name('parking-lots.zones.index');
        Route::post('{lot}/zones', [ParkingZoneController::class, 'store'])
            ->middleware('permission:parking.create')
            ->name('parking-lots.zones.store');
        Route::patch('{lot}/zones/{zone}', [ParkingZoneController::class, 'update'])
            ->middleware('permission:parking.update')
            ->name('parking-lots.zones.update');
        Route::delete('{lot}/zones/{zone}', [ParkingZoneController::class, 'destroy'])
            ->middleware('permission:parking.delete')
            ->name('parking-lots.zones.destroy');

        Route::get('{lot}/spaces', [ParkingSpaceController::class, 'index'])
            ->middleware('permission:parking.view')
            ->name('parking-lots.spaces.index');
        Route::post('{lot}/spaces', [ParkingSpaceController::class, 'store'])
            ->middleware('permission:parking.create')
            ->name('parking-lots.spaces.store');
        Route::post('{lot}/spaces/bulk', [ParkingSpaceController::class, 'bulk'])
            ->middleware(['permission:parking.create', 'throttle:20,1'])
            ->name('parking-lots.spaces.bulk');
        Route::patch('{lot}/spaces/{space}', [ParkingSpaceController::class, 'update'])
            ->middleware('permission:parking.update')
            ->name('parking-lots.spaces.update');
        Route::post('{lot}/spaces/{space}/status', [ParkingSpaceController::class, 'status'])
            ->middleware('permission:parking.update')
            ->name('parking-lots.spaces.status');
        Route::delete('{lot}/spaces/{space}', [ParkingSpaceController::class, 'destroy'])
            ->middleware('permission:parking.delete')
            ->name('parking-lots.spaces.destroy');

        Route::get('{lot}/gates', [ParkingGateController::class, 'index'])
            ->middleware('permission:parking.view')
            ->name('parking-lots.gates.index');
        Route::post('{lot}/gates', [ParkingGateController::class, 'store'])
            ->middleware('permission:parking.create')
            ->name('parking-lots.gates.store');
        Route::patch('{lot}/gates/{gate}', [ParkingGateController::class, 'update'])
            ->middleware('permission:parking.update')
            ->name('parking-lots.gates.update');
        Route::delete('{lot}/gates/{gate}', [ParkingGateController::class, 'destroy'])
            ->middleware('permission:parking.delete')
            ->name('parking-lots.gates.destroy');
    });
