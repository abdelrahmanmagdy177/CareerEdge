<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Profiles\Contracts\SessionCvAccess;
use App\Modules\Profiles\Models\Cv;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Models\Session;

/**
 * Business rules 10 and 11: the recruiter of a session can read the CV attached to it,
 * until 7 days after the session ends. This replaces Profiles' "nobody can" default.
 */
final class RecruiterSessionCvAccess implements SessionCvAccess
{
    public const DAYS_AFTER_SESSION = 7;

    public function canRead(User $user, Cv $cv): bool
    {
        return Session::where('recruiter_id', $user->id)
            ->where('cv_id', $cv->id)
            // Paid for at some point. An unpaid attempt gives no access.
            ->whereNotNull('confirmed_at')
            // A cancelled session ends the recruiter's reason to see the CV.
            ->where('status', '!=', SessionStatus::Cancelled)
            ->where('scheduled_end', '>', now()->subDays(self::DAYS_AFTER_SESSION))
            ->exists();
    }
}
