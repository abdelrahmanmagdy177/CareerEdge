<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Broadcasts\SessionStatusChanged;
use App\Modules\Scheduling\Contracts\FinishedSession;
use App\Modules\Scheduling\Contracts\SessionOutcomes;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Events\DisputeResolved;
use App\Modules\Scheduling\Events\SessionDisputed;
use App\Modules\Scheduling\Models\Session;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

final class SessionOutcomesService implements SessionOutcomes
{
    public function forCandidate(string $sessionId, string $candidateId): ?FinishedSession
    {
        $session = Session::whereKey($sessionId)->where('candidate_id', $candidateId)->first();

        return $session === null ? null : new FinishedSession(
            $session->id,
            $session->candidate_id,
            $session->recruiter_id,
            $session->status,
            $session->actual_end ?? $session->scheduled_end,
        );
    }

    public function markDisputed(string $sessionId): bool
    {
        $disputed = Session::whereKey($sessionId)
            ->where('status', SessionStatus::Completed)
            ->update(['status' => SessionStatus::Disputed]);

        if ($disputed === 0) {
            return false;
        }

        event(new SessionDisputed($sessionId));
        Realtime::send(new SessionStatusChanged($sessionId, SessionStatus::Disputed));

        return true;
    }

    public function resolveDispute(string $sessionId, bool $refundCandidate): bool
    {
        $status = $refundCandidate ? SessionStatus::Refunded : SessionStatus::Completed;

        $resolved = Session::whereKey($sessionId)
            ->where('status', SessionStatus::Disputed)
            ->update(['status' => $status]);

        if ($resolved === 0) {
            return false;
        }

        // Payments reacts inside the caller's transaction, so the money and the status
        // change together. The apps are told only once all of it is saved.
        event(new DisputeResolved($sessionId, $refundCandidate));
        DB::afterCommit(fn () => Realtime::send(new SessionStatusChanged($sessionId, $status)));

        return true;
    }
}
