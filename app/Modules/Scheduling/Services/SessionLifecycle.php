<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Scheduling\Broadcasts\SessionEndingSoon;
use App\Modules\Scheduling\Broadcasts\SessionStatusChanged;
use App\Modules\Scheduling\Enums\SessionMode;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Events\SessionCompleted;
use App\Modules\Scheduling\Events\SessionNoShow;
use App\Modules\Scheduling\Models\Session;
use App\Modules\Scheduling\Voice\VoiceProvider;
use App\Support\Http\ApiError;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * Moves a paid session through its live part:
 *
 *   confirmed --first join--> in_progress --recruiter ends, or time is up--> completed
 *   confirmed/in_progress, recruiter never joined, time is up --> no_show
 *
 * Every change of status is one UPDATE that names the status it expects to find, so two
 * things happening at once (the recruiter ends while the job closes) cannot both win.
 */
final class SessionLifecycle
{
    /** A session is closed automatically this long after its scheduled end. */
    public const CLOSE_AFTER_MINUTES = 10;

    public const ENDING_SOON_MINUTES = 5;

    public function __construct(
        private readonly VoiceProvider $voice,
        private readonly UserDirectory $users,
    ) {}

    /**
     * A participant enters the session.
     *
     * @return array<string, mixed>|null the `voice` object for a voice session, null for chat
     */
    public function join(Session $session, string $userId): ?array
    {
        if (! in_array($session->status, [SessionStatus::Confirmed, SessionStatus::InProgress], true)) {
            throw ApiError::conflict('INVALID_SESSION_STATE', 'This session cannot be joined');
        }

        // Business rule 6: from 10 minutes before the start until the scheduled end.
        $opensAt = $session->scheduled_start->copy()->subMinutes(Session::JOIN_EARLY_MINUTES);
        if (now()->lessThan($opensAt) || now()->greaterThanOrEqualTo($session->scheduled_end)) {
            throw ApiError::conflict('SESSION_NOT_JOINABLE', 'The session can be joined from 10 minutes before it starts until it ends');
        }

        // Remember who showed up. Joining again changes nothing: the first time is kept.
        DB::table('session_attendances')->insertOrIgnore([
            'session_id' => $session->id,
            'user_id' => $userId,
            'first_joined_at' => now(),
        ]);

        $started = Session::whereKey($session->id)
            ->where('status', SessionStatus::Confirmed)
            ->update(['status' => SessionStatus::InProgress, 'actual_start' => now()]);

        if ($started === 1) {
            Realtime::send(new SessionStatusChanged($session->id, SessionStatus::InProgress));
        }

        if ($session->mode !== SessionMode::Voice) {
            return null;
        }

        // The pass outlives the session by the closing margin, so a call is not cut mid-sentence.
        return $this->voice->access(
            roomName: "session-{$session->id}",
            userId: $userId,
            displayName: $this->users->summaries([$userId])[$userId]['fullName'],
            expiresAt: $session->scheduled_end->copy()->addMinutes(self::CLOSE_AFTER_MINUTES),
        );
    }

    /** The recruiter ends the session. */
    public function end(Session $session): void
    {
        $ended = Session::whereKey($session->id)
            ->where('status', SessionStatus::InProgress)
            ->update(['status' => SessionStatus::Completed, 'actual_end' => now()]);

        if ($ended === 0) {
            throw ApiError::conflict('INVALID_SESSION_STATE', 'Only a session that is in progress can be ended');
        }

        $this->announce($session->id, SessionStatus::Completed);
    }

    /**
     * Closes sessions whose time ran out 10 minutes ago and that nobody ended.
     * Business rule 7: if the recruiter was there it is completed, otherwise it is a no-show.
     *
     * @return array{completed: int, noShow: int}
     */
    public function closeFinished(): array
    {
        $counts = ['completed' => 0, 'noShow' => 0];
        $open = [SessionStatus::Confirmed, SessionStatus::InProgress];

        $due = Session::whereIn('status', $open)
            ->where('scheduled_end', '<=', now()->subMinutes(self::CLOSE_AFTER_MINUTES))
            ->get(['id', 'recruiter_id', 'scheduled_end']);

        foreach ($due as $session) {
            $recruiterCame = DB::table('session_attendances')
                ->where('session_id', $session->id)
                ->where('user_id', $session->recruiter_id)
                ->exists();

            $status = $recruiterCame ? SessionStatus::Completed : SessionStatus::NoShow;

            $closed = Session::whereKey($session->id)
                ->whereIn('status', $open)
                // The nominal end: nobody pressed "end", so the scheduled time stands.
                ->update(['status' => $status, 'actual_end' => $recruiterCame ? $session->scheduled_end : null]);

            if ($closed === 1) {
                $counts[$recruiterCame ? 'completed' : 'noShow']++;
                $this->announce($session->id, $status);
            }
        }

        return $counts;
    }

    /** @return int how many sessions were told they have about 5 minutes left */
    public function notifyEndingSoon(): int
    {
        $due = Session::where('status', SessionStatus::InProgress)
            ->whereNull('ending_soon_notified_at')
            ->where('scheduled_end', '>', now())
            ->where('scheduled_end', '<=', now()->addMinutes(self::ENDING_SOON_MINUTES))
            ->get(['id', 'scheduled_end']);

        $sent = 0;

        foreach ($due as $session) {
            // Mark first, inside the UPDATE's own condition, so two overlapping runs of the
            // job cannot both send it.
            $marked = Session::whereKey($session->id)
                ->whereNull('ending_soon_notified_at')
                ->update(['ending_soon_notified_at' => now()]);

            if ($marked === 1) {
                Realtime::send(new SessionEndingSoon($session->id, $session->scheduled_end));
                $sent++;
            }
        }

        return $sent;
    }

    /** Tells the apps (live event) and the other modules (plain event) about a final status. */
    private function announce(string $sessionId, SessionStatus $status): void
    {
        Realtime::send(new SessionStatusChanged($sessionId, $status));

        event($status === SessionStatus::Completed ? new SessionCompleted($sessionId) : new SessionNoShow($sessionId));
    }
}
