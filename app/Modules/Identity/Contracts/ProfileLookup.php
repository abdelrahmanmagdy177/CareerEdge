<?php

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Models\User;

/**
 * Answers "has this user finished onboarding?" for the `hasProfile` field of the user payload.
 *
 * Identity does not know about profiles. Until the Profiles module exists, the default
 * binding (NoProfilesYet) always says no. The Profiles module rebinds this interface.
 */
interface ProfileLookup
{
    public function hasProfile(User $user): bool;
}
