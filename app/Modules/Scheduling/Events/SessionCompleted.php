<?php

namespace App\Modules\Scheduling\Events;

/** A session finished normally. Payments listens to this to schedule the recruiter's payout (M6). */
final readonly class SessionCompleted
{
    public function __construct(public string $sessionId) {}
}
