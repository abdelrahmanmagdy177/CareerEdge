<?php

namespace App\Modules\Profiles\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Profiles\Models\Cv;

/**
 * Answers "may this user read a CV that is not theirs?".
 *
 * The rule (docs/PLAN.md, business rules 10 and 11): the recruiter of a session can read the
 * CV attached to it, until 7 days after the session ends. Sessions belong to the Scheduling
 * module, which does not exist yet, so the default binding (NoSessionsYet) always says no.
 * Scheduling rebinds this interface when it is built.
 */
interface SessionCvAccess
{
    public function canRead(User $user, Cv $cv): bool;
}
