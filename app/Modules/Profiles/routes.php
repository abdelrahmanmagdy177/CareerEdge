<?php

use App\Modules\Profiles\Http\Controllers\CandidateProfileController;
use App\Modules\Profiles\Http\Controllers\CvController;
use App\Modules\Profiles\Http\Controllers\RecruiterProfileController;
use App\Modules\Profiles\Http\Controllers\SpecializationController;
use Illuminate\Support\Facades\Route;

Route::get('/specializations', [SpecializationController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('role:candidate')->group(function () {
        Route::get('/me/candidate-profile', [CandidateProfileController::class, 'show']);
        Route::put('/me/candidate-profile', [CandidateProfileController::class, 'upsert']);

        Route::get('/me/cvs', [CvController::class, 'index']);
        Route::post('/me/cvs', [CvController::class, 'store']);
        // whereUuid: an id that is not a UUID never reaches the database, where comparing it
        // with a uuid column would raise an error. It is a plain 404 instead.
        Route::delete('/me/cvs/{cv}', [CvController::class, 'destroy'])->whereUuid('cv');
    });

    Route::middleware('role:recruiter')->group(function () {
        Route::get('/me/recruiter-profile', [RecruiterProfileController::class, 'show']);
        Route::put('/me/recruiter-profile', [RecruiterProfileController::class, 'upsert']);
    });

    // Any role: the owner, or later the recruiter of a session that uses this CV.
    Route::get('/cvs/{cv}/download-url', [CvController::class, 'downloadUrl'])->whereUuid('cv');
});
