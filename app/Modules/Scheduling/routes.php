<?php

use App\Modules\Scheduling\Http\Controllers\OfferingController;
use App\Modules\Scheduling\Http\Controllers\RecruiterDiscoveryController;
use App\Modules\Scheduling\Http\Controllers\SlotController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Finding a recruiter: any logged-in user.
    Route::get('/recruiters', [RecruiterDiscoveryController::class, 'search']);
    Route::get('/recruiters/{recruiter}', [RecruiterDiscoveryController::class, 'show'])->whereUuid('recruiter');
    Route::get('/recruiters/{recruiter}/slots', [RecruiterDiscoveryController::class, 'slots'])->whereUuid('recruiter');

    // A recruiter managing what they sell and when.
    Route::middleware('role:recruiter')->group(function () {
        Route::get('/me/offerings', [OfferingController::class, 'index']);
        Route::get('/me/slots', [SlotController::class, 'index']);

        // Publishing something candidates could book needs an approved profile.
        // Reading, switching off and deleting do not.
        Route::middleware('recruiter.approved')->group(function () {
            Route::post('/me/offerings', [OfferingController::class, 'store']);
            Route::post('/me/slots', [SlotController::class, 'store']);
        });

        Route::patch('/me/offerings/{offering}', [OfferingController::class, 'update'])->whereUuid('offering');
        Route::delete('/me/slots/{slot}', [SlotController::class, 'destroy'])->whereUuid('slot');
    });
});
