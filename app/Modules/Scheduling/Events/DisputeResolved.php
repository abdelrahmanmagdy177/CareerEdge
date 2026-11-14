<?php

namespace App\Modules\Scheduling\Events;

/**
 * An admin closed a dispute. Payments listens to this: refund the candidate in full,
 * or pay the recruiter now.
 */
final readonly class DisputeResolved
{
    public function __construct(public string $sessionId, public bool $refundCandidate) {}
}
