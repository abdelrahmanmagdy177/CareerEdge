<?php

use App\Modules\Reviews\Http\Controllers\Admin\DisputeResolutionController;
use App\Modules\Reviews\Http\Controllers\DisputeController;
use App\Modules\Reviews\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/recruiters/{recruiter}/reviews', [ReviewController::class, 'index'])->whereUuid('recruiter');

    // Admins settle disputes.
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/disputes', [DisputeResolutionController::class, 'index']);
        Route::post('/admin/disputes/{dispute}/resolve', [DisputeResolutionController::class, 'resolve'])->whereUuid('dispute');
    });

    Route::middleware('role:candidate')->group(function () {
        Route::post('/sessions/{session}/review', [ReviewController::class, 'store'])->whereUuid('session');
        Route::post('/sessions/{session}/dispute', [DisputeController::class, 'store'])->whereUuid('session');
    });
});
