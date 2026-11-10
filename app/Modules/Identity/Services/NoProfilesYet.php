<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\ProfileLookup;
use App\Modules\Identity\Models\User;

final class NoProfilesYet implements ProfileLookup
{
    public function hasProfile(User $user): bool
    {
        return false;
    }
}
