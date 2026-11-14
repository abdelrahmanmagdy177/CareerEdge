<?php

namespace App\Modules\Scheduling\Contracts;

/**
 * "May this user see this session?", for other modules (the chat) and for the live channel.
 * The answer follows the same rule as GET /sessions/{id}.
 */
interface SessionAccess
{
    /** Null when the session does not exist or this user may not see it. */
    public function view(string $sessionId, string $userId, string $role): ?SessionView;
}
