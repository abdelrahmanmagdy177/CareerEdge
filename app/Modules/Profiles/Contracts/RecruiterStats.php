<?php

namespace App\Modules\Profiles\Contracts;

/**
 * Numbers about a recruiter that other modules produce and the recruiter's profile shows.
 * The profile row keeps a copy so search can filter and sort by them without counting
 * reviews on every request.
 */
interface RecruiterStats
{
    /** The Reviews module calls this after every new review. */
    public function recordRating(string $recruiterId, float $average, int $count): void;

    /** The Scheduling module calls this when a recruiter cancels a paid session. */
    public function recordCancellation(string $recruiterId): void;
}
