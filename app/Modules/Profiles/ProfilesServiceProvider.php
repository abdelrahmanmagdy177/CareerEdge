<?php

namespace App\Modules\Profiles;

use App\Modules\Identity\Contracts\ProfileLookup;
use App\Modules\Profiles\Contracts\ProfileDirectory;
use App\Modules\Profiles\Contracts\RecruiterStats;
use App\Modules\Profiles\Contracts\RecruiterVerification;
use App\Modules\Profiles\Contracts\SessionCvAccess;
use App\Modules\Profiles\Http\Middleware\EnsureApprovedRecruiter;
use App\Modules\Profiles\Services\EloquentProfileDirectory;
use App\Modules\Profiles\Services\EloquentRecruiterStats;
use App\Modules\Profiles\Services\NoSessionsYet;
use App\Modules\Profiles\Services\ProfileStatus;
use App\Modules\Profiles\Services\RecruiterVerificationStatus;
use Illuminate\Support\ServiceProvider;

class ProfilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Replaces Identity's "nobody has a profile" default with the real answer.
        $this->app->bind(ProfileLookup::class, ProfileStatus::class);

        // What other modules may ask about a recruiter.
        $this->app->bind(RecruiterVerification::class, RecruiterVerificationStatus::class);

        // Where other modules report a recruiter's rating and cancellations.
        $this->app->bind(RecruiterStats::class, EloquentRecruiterStats::class);

        // What Scheduling needs when a session is booked and shown.
        $this->app->bind(ProfileDirectory::class, EloquentProfileDirectory::class);

        // bindIf: the Scheduling module binds the real rule.
        $this->app->bindIf(SessionCvAccess::class, NoSessionsYet::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Other modules protect recruiter-only actions with `recruiter.approved`.
        $this->app['router']->aliasMiddleware('recruiter.approved', EnsureApprovedRecruiter::class);
    }
}
