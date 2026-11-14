<?php

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Profiles\ProfilesServiceProvider;
use App\Modules\Scheduling\SchedulingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // One provider per module. It registers the module's migrations, bindings and rate limits.
    IdentityServiceProvider::class,
    ProfilesServiceProvider::class,
    SchedulingServiceProvider::class,
    PaymentsServiceProvider::class,
];
