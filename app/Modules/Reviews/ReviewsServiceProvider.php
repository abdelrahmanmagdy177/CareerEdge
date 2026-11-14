<?php

namespace App\Modules\Reviews;

use App\Modules\Scheduling\Contracts\ReviewLookup;
use Illuminate\Support\ServiceProvider;

class ReviewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Gives Scheduling the `hasReview` answer it shows on a session.
        $this->app->bind(ReviewLookup::class, ReviewedSessions::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
