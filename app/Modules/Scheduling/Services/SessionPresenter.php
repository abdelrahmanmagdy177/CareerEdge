<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Profiles\Contracts\ProfileDirectory;
use App\Modules\Scheduling\Contracts\PaymentStatusLookup;
use App\Modules\Scheduling\Contracts\ReviewLookup;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Models\Session;
use App\Support\Http\ApiDate;

/**
 * Builds the `Session` schema of docs/openapi.yaml.
 *
 * A session shows names (Identity), a specialization and a CV (Profiles) and a payment status
 * (Payments) and whether it was reviewed (Reviews). Those belong to other modules, so they are fetched through their interfaces,
 * once for the whole list: a page of 20 sessions costs 5 lookups, not 100.
 */
final class SessionPresenter
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly ProfileDirectory $profiles,
        private readonly PaymentStatusLookup $payments,
        private readonly ReviewLookup $reviews,
    ) {}

    /** @return array<string, mixed> */
    public function one(Session $session): array
    {
        return $this->many([$session])[0];
    }

    /**
     * @param  iterable<Session>  $sessions
     * @return list<array<string, mixed>>
     */
    public function many(iterable $sessions): array
    {
        $sessions = collect($sessions)->values();

        $users = $this->users->summaries($sessions->flatMap(fn (Session $s) => [$s->candidate_id, $s->recruiter_id])->all());
        $specializations = $this->profiles->specializations($sessions->pluck('specialization_id')->filter()->all());
        $cvs = $this->profiles->cvs($sessions->pluck('cv_id')->filter()->all());
        $paymentStatuses = $this->payments->statusesFor($sessions->pluck('id')->all());
        $reviewed = $this->reviews->reviewedSessionIds($sessions->pluck('id')->all());

        return $sessions->map(fn (Session $s) => [
            'id' => $s->id,
            'type' => $s->type->value,
            'mode' => $s->mode->value,
            'status' => $s->status->value,
            'candidate' => $users[$s->candidate_id],
            'recruiter' => $users[$s->recruiter_id],
            'specialization' => $specializations[$s->specialization_id] ?? null,
            'scheduledStart' => ApiDate::format($s->scheduled_start),
            'scheduledEnd' => ApiDate::format($s->scheduled_end),
            'durationMinutes' => $s->duration_minutes,
            'actualStart' => $s->actual_start ? ApiDate::format($s->actual_start) : null,
            'actualEnd' => $s->actual_end ? ApiDate::format($s->actual_end) : null,
            'holdExpiresAt' => $s->hold_expires_at ? ApiDate::format($s->hold_expires_at) : null,
            'price' => $s->price()->toArray(),
            'paymentStatus' => $paymentStatuses[$s->id] ?? null,
            'cv' => $cvs[$s->cv_id] ?? null,
            'candidateNotes' => $s->candidate_notes,
            // Business rule 8: messages can be sent from confirmed until the session is completed.
            'chatOpen' => in_array($s->status, [SessionStatus::Confirmed, SessionStatus::InProgress], true),
            'canJoinFrom' => ApiDate::format($s->scheduled_start->copy()->subMinutes(Session::JOIN_EARLY_MINUTES)),
            'cancelledBy' => $s->cancelled_by,
            'cancelReason' => $s->cancel_reason,
            'hasReview' => in_array($s->id, $reviewed, true),
            // Scorecards arrive in phase 2.
            'hasScorecard' => false,
            'createdAt' => ApiDate::format($s->created_at),
        ])->all();
    }
}
