<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Http\Controllers\AuthenticatedSessionController;
use App\Modules\Identity\Presentation\Http\Controllers\CurrentUserController;
use App\Modules\Identity\Presentation\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Presentation\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Presentation\Http\Controllers\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register')
        ->name('auth.register');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::post('forgot-password', [PasswordResetController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('auth.password.forgot');

    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:password-reset')
        ->name('auth.password.reset');

    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware(['auth', 'tenant.context'])->group(function (): void {
        Route::get('me', [CurrentUserController::class, 'show'])->name('auth.me');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('auth.logout');
        Route::post('email/verification-notification', [EmailVerificationController::class, 'store'])
            ->middleware('throttle:email-verification')
            ->name('auth.email.resend');
    });
});
