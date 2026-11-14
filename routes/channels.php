<?php

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Contracts\SessionAccess;
use Illuminate\Support\Facades\Broadcast;

/*
| Who may listen to which live channel.
|
| The app subscribes to "private-session.{id}". Before the WebSocket server lets it in, the
| app's library calls POST /api/v1/broadcasting/auth with the user's access token, and that
| request ends up in the callback below. Returning false refuses the subscription.
*/

// The same rule as GET /sessions/{id}: the candidate, the recruiter once it is paid, or an admin.
Broadcast::channel('session.{sessionId}', fn (User $user, string $sessionId) => app(SessionAccess::class)
    ->view($sessionId, $user->id, $user->role->value) !== null);
