<?php

namespace App\Modules\Profiles\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Profiles\Contracts\SessionCvAccess;
use App\Modules\Profiles\Models\Cv;

final class NoSessionsYet implements SessionCvAccess
{
    public function canRead(User $user, Cv $cv): bool
    {
        return false;
    }
}
