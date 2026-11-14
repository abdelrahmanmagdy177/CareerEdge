<?php

namespace App\Modules\Scheduling\Contracts;

/**
 * How the Reviews module (reviews and disputes) talks to sessions.
 */
interface SessionOutcomes
{
    /** The session, if this candidate booked it. Null otherwise. */
    public function forCandidate(string $sessionId, string $candidateId): ?FinishedSession;

    /**
     * Moves a completed session to `disputed` and tells the other modules.
     *
     * @return bool false if the session was not `completed` any more
     */
    public function markDisputed(string $sessionId): bool;

    /**
     * An admin decided a dispute. Moves the session from `disputed` to `refunded` (the
     * candidate gets the money back) or back to `completed` (the recruiter is paid), and
     * tells the other modules.
     *
     * @return bool false if the session was not `disputed` any more
     */
    public function resolveDispute(string $sessionId, bool $refundCandidate): bool;
}
