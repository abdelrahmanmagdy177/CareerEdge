<?php

use App\Modules\Reviews\Http\Controllers\DisputeController;
use App\Modules\Reviews\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/recruiters/{recruiter}/reviews', [ReviewController::class, 'index'])->whereUuid('recruiter');

    Route::middleware('role:candidate')->group(function () {
        Route::post('/sessions/{session}/review', [ReviewController::class, 'store'])->whereUuid('session');
        Route::post('/sessions/{session}/dispute', [DisputeController::class, 'store'])->whereUuid('session');
    });
});
