<?php

namespace App\Modules\Reviews;

use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Contracts\ReviewLookup;

/** Tells Scheduling which sessions have a review, for the `hasReview` field. */
final class ReviewedSessions implements ReviewLookup
{
    public function reviewedSessionIds(array $sessionIds): array
    {
        return Review::whereIn('session_id', $sessionIds)->pluck('session_id')->all();
    }
}
