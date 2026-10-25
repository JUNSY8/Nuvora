<?php

declare(strict_types=1);

use App\Modules\Payments\Presentation\Http\Controllers\OnlinePaymentController;
use Illuminate\Support\Facades\Route;

// The account in the URL is only trusted once the signature made with its secret checks out.
Route::post('webhooks/payments/{provider}/{account}', [OnlinePaymentController::class, 'webhook'])
    ->whereIn('provider', ['wompi', 'payu'])
    ->whereUlid('account')
    ->middleware('throttle:240,1')
    ->name('webhooks.payments');
