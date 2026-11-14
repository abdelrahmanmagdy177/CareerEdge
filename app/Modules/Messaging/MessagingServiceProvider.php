<?php

namespace App\Modules\Messaging;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class MessagingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Counted per user, not per IP: two people in one office must not share a limit.
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(
            config('careeredge.messages_per_minute'),
        )->by($request->user()->id));
    }
}
