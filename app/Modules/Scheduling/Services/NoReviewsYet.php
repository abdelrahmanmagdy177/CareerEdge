<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\ReviewLookup;

/** Used only if the Reviews module is not installed. */
final class NoReviewsYet implements ReviewLookup
{
    public function reviewedSessionIds(array $sessionIds): array
    {
        return [];
    }
}
