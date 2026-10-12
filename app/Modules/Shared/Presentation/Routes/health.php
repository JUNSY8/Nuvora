<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/health', [HealthController::class, 'show'])->name('health.show');
