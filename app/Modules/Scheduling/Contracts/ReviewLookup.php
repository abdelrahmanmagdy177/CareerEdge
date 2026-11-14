<?php

namespace App\Modules\Scheduling\Contracts;

/**
 * Fills the `hasReview` field of a session. Scheduling owns this interface and the Reviews
 * module implements it.
 */
interface ReviewLookup
{
    /**
     * @param  list<string>  $sessionIds
     * @return list<string> the ids among them that have a review
     */
    public function reviewedSessionIds(array $sessionIds): array;
}
