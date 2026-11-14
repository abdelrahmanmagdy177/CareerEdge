<?php

use App\Support\Idempotency\IdempotencyKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deletes saved Idempotency-Key answers older than 24 hours.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
