<?php

namespace App\Modules\Scheduling\Events;

/** The candidate opened a dispute. Payments listens to this to stop the recruiter's payout. */
final readonly class SessionDisputed
{
    public function __construct(public string $sessionId) {}
}
