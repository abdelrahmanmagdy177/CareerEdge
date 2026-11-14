<?php

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\Enums\SessionStatus;

/** What another module may know about a session that a given user is allowed to see. */
final readonly class SessionView
{
    public function __construct(
        public string $id,
        public string $candidateId,
        public string $recruiterId,
        public SessionStatus $status,
    ) {}

    /** The candidate or the recruiter of the session (an admin can look, but is not one). */
    public function isParticipant(string $userId): bool
    {
        return $userId === $this->candidateId || $userId === $this->recruiterId;
    }

    /** Business rule 8: messages can be sent from confirmed until the session is completed. */
    public function chatOpen(): bool
    {
        return in_array($this->status, [SessionStatus::Confirmed, SessionStatus::InProgress], true);
    }
}
