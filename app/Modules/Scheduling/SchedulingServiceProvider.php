<?php

namespace App\Modules\Scheduling;

use App\Modules\Profiles\Contracts\SessionCvAccess;
use App\Modules\Scheduling\Console\ExpireHolds;
use App\Modules\Scheduling\Contracts\PaymentStatusLookup;
use App\Modules\Scheduling\Contracts\SessionBilling;
use App\Modules\Scheduling\Services\NoPaymentsYet;
use App\Modules\Scheduling\Services\RecruiterSessionCvAccess;
use App\Modules\Scheduling\Services\SessionBillingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // What the Payments module may ask and tell about sessions.
        $this->app->bind(SessionBilling::class, SessionBillingService::class);

        // bindIf: the Payments module binds the real lookup.
        $this->app->bindIf(PaymentStatusLookup::class, NoPaymentsYet::class);

        // Replaces Profiles' "no recruiter can read a CV" default with the real session rule.
        $this->app->bind(SessionCvAccess::class, RecruiterSessionCvAccess::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        $this->commands([ExpireHolds::class]);

        // Runs only while the scheduler is running: `php artisan schedule:work` in development,
        // a cron entry for `schedule:run` in production (see the README).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(ExpireHolds::class)->everyMinute()->withoutOverlapping();
        });
    }
}
