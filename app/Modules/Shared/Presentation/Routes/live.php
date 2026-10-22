<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Http\Controllers\LiveUpdatesController;
use Illuminate\Support\Facades\Route;

// Polled by open screens every few seconds; see ADR-015.
Route::middleware(['auth', 'tenant.context', 'tenant.required', 'throttle:120,1'])
    ->get('live', [LiveUpdatesController::class, 'show'])
    ->name('live.show');
