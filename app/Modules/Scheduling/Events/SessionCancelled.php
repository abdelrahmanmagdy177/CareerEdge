<?php

namespace App\Modules\Scheduling\Events;

/**
 * A session was cancelled. Other modules listen to this instead of being called directly,
 * so Scheduling does not need to know who cares (today: Payments).
 */
final readonly class SessionCancelled
{
    public function __construct(public string $sessionId) {}
}
