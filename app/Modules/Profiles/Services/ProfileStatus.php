<?php

namespace App\Modules\Profiles\Services;

use App\Modules\Identity\Contracts\ProfileLookup;
use App\Modules\Identity\Enums\UserRole;
use App\Modules\Identity\Models\User;
use App\Modules\Profiles\Models\CandidateProfile;
use App\Modules\Profiles\Models\RecruiterProfile;

/**
 * The real answer to Identity's `hasProfile` question, replacing its NoProfilesYet default.
 * This is how the two modules talk: Identity owns the interface, Profiles implements it.
 */
final class ProfileStatus implements ProfileLookup
{
    public function hasProfile(User $user): bool
    {
        return match ($user->role) {
            UserRole::Candidate => CandidateProfile::whereKey($user->id)->exists(),
            UserRole::Recruiter => RecruiterProfile::whereKey($user->id)->exists(),
            // Admins have no profile to fill in, so the app must not send them to onboarding.
            UserRole::Admin => true,
        };
    }
}
