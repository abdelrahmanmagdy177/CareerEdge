<?php

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Profiles\ProfilesServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // One provider per module. It registers the module's migrations, bindings and rate limits.
    IdentityServiceProvider::class,
    ProfilesServiceProvider::class,
];
