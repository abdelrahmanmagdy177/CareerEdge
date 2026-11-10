<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Contracts\ProfileLookup;
use App\Modules\Identity\Services\NoProfilesYet;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // bindIf: the Profiles module can bind its own implementation without caring about load order.
        $this->app->bindIf(ProfileLookup::class, NoProfilesYet::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // The contract returns objects directly, not inside a {"data": ...} wrapper.
        JsonResource::withoutWrapping();

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(
            config('careeredge.auth_rate_limit_per_minute'),
        )->by($request->ip()));
    }
}
