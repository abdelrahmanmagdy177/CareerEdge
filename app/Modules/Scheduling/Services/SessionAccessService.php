<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\SessionAccess;
use App\Modules\Scheduling\Contracts\SessionView;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Support\Str;

final class SessionAccessService implements SessionAccess
{
    public function view(string $sessionId, string $userId, string $role): ?SessionView
    {
        // The id can come from a channel name typed by a client, so it may be anything.
        if (! Str::isUuid($sessionId)) {
            return null;
        }

        $session = Session::query()->visibleTo($userId, $role)->find($sessionId);

        return $session === null
            ? null
            : new SessionView($session->id, $session->candidate_id, $session->recruiter_id, $session->status);
    }
}
