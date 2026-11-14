<?php

use App\Modules\Messaging\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sessions/{session}/messages', [MessageController::class, 'index'])->whereUuid('session');

    // At most 30 messages a minute per user (the "messages" limiter is in MessagingServiceProvider).
    Route::post('/sessions/{session}/messages', [MessageController::class, 'store'])
        ->whereUuid('session')
        ->middleware('throttle:messages');
});
