<?php

namespace App\Modules\Scheduling\Events;

/**
 * A session was cancelled. Other modules listen to this instead of being called directly,
 * so Scheduling does not need to know who cares (today: Payments).
 */
final readonly class SessionCancelled
{
    /**
     * @param  int  $refundPercent  how much of a held payment goes back to the candidate: 0, 50 or 100.
     *                              Scheduling decides this (it owns the cancellation rules),
     *                              and Payments only carries it out.
     */
    public function __construct(public string $sessionId, public int $refundPercent = 0) {}
}
