<?php

use App\Modules\Payments\Http\Controllers\CheckoutController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/sessions/{session}/checkout', [CheckoutController::class, 'store'])
        ->whereUuid('session')
        ->middleware(['role:candidate', 'idempotent']);

    Route::get('/sessions/{session}/payment', [PaymentController::class, 'show'])->whereUuid('session');
});

// No login: the payment provider calls this, and its signature is checked instead.
Route::post('/payments/webhooks/{provider}', [WebhookController::class, 'handle']);
