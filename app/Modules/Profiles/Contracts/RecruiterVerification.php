<?php

namespace App\Modules\Profiles\Contracts;

/**
 * The question other modules may ask Profiles about a recruiter.
 * Only approved recruiters can publish offerings and slots, be found, and be booked.
 */
interface RecruiterVerification
{
    /** False also when the user has no recruiter profile at all. */
    public function isApproved(string $userId): bool;
}
