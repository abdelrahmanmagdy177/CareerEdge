<?php

namespace App\Modules\Scheduling\Events;

/** The recruiter never joined. Payments listens to this to refund the candidate (M6). */
final readonly class SessionNoShow
{
    public function __construct(public string $sessionId) {}
}
