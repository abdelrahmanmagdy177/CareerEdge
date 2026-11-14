<?php

namespace App\Modules\Scheduling;

use App\Modules\Profiles\Contracts\SessionCvAccess;
use App\Modules\Scheduling\Console\CloseFinishedSessions;
use App\Modules\Scheduling\Console\ExpireHolds;
use App\Modules\Scheduling\Contracts\PaymentStatusLookup;
use App\Modules\Scheduling\Contracts\SessionAccess;
use App\Modules\Scheduling\Contracts\SessionBilling;
use App\Modules\Scheduling\Services\NoPaymentsYet;
use App\Modules\Scheduling\Services\RecruiterSessionCvAccess;
use App\Modules\Scheduling\Services\SessionAccessService;
use App\Modules\Scheduling\Services\SessionBillingService;
use App\Modules\Scheduling\Voice\FakeVoiceProvider;
use App\Modules\Scheduling\Voice\LiveKitVoiceProvider;
use App\Modules\Scheduling\Voice\VoiceProvider;
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

        // "May this user see this session?", for the chat module and the live channel.
        $this->app->bind(SessionAccess::class, SessionAccessService::class);

        // The voice call service. "fake" hands out placeholder passes for development.
        $this->app->bind(VoiceProvider::class, fn () => config('careeredge.voice.provider') === 'livekit'
            ? new LiveKitVoiceProvider(
                config('careeredge.voice.livekit.url'),
                config('careeredge.voice.livekit.api_key'),
                config('careeredge.voice.livekit.api_secret'),
            )
            : new FakeVoiceProvider);

        // Replaces Profiles' "no recruiter can read a CV" default with the real session rule.
        $this->app->bind(SessionCvAccess::class, RecruiterSessionCvAccess::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        $this->commands([ExpireHolds::class, CloseFinishedSessions::class]);

        // Runs only while the scheduler is running: `php artisan schedule:work` in development,
        // a cron entry for `schedule:run` in production (see the README).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(ExpireHolds::class)->everyMinute()->withoutOverlapping();
            $schedule->command(CloseFinishedSessions::class)->everyMinute()->withoutOverlapping();
        });
    }
}
