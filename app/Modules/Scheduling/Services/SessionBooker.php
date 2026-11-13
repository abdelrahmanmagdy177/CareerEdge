<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Profiles\Contracts\ProfileDirectory;
use App\Modules\Profiles\Contracts\RecruiterVerification;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Enums\SessionType;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Offering;
use App\Modules\Scheduling\Models\Session;
use App\Support\Http\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a session: checks the request against the rules, takes the slot, and creates the
 * session in `pending_payment` with a 15-minute hold.
 */
final class SessionBooker
{
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private readonly RecruiterVerification $recruiters,
        private readonly ProfileDirectory $profiles,
    ) {}

    /**
     * @param  array{offeringId: string, slotId: string, cvId?: string|null, candidateNotes?: string|null}  $data
     */
    public function book(string $candidateId, array $data): Session
    {
        $offering = Offering::whereKey($data['offeringId'])->where('is_active', true)->first();
        if ($offering === null || ! $this->recruiters->isApproved($offering->recruiter_id)) {
            throw ApiError::notFound('Offering not found');
        }

        // The slot must be this recruiter's. Someone else's slot id is simply "not found".
        $slot = AvailabilitySlot::whereKey($data['slotId'])->where('recruiter_id', $offering->recruiter_id)->first();
        if ($slot === null) {
            throw ApiError::notFound('Slot not found');
        }

        $start = $slot->starts_at;
        $end = $start->copy()->addMinutes($offering->duration_minutes);
        if ($end->greaterThan($slot->ends_at)) {
            throw ValidationException::withMessages([
                'slotId' => ["This slot is too short for a {$offering->duration_minutes}-minute session."],
            ]);
        }

        $cvId = $data['cvId'] ?? null;
        if ($cvId === null && $offering->session_type === SessionType::CvReview) {
            throw ValidationException::withMessages(['cvId' => ['A CV review needs a CV. Choose one of your CVs.']]);
        }
        if ($cvId !== null && ! $this->profiles->candidateOwnsCv($candidateId, $cvId)) {
            throw ValidationException::withMessages(['cvId' => ['Choose one of your CVs.']]);
        }

        $timeConflict = ApiError::conflict('CANDIDATE_TIME_CONFLICT', 'You already have a session at this time');
        if (Session::where('candidate_id', $candidateId)->occupying()
            ->where('scheduled_start', '<', $end)->where('scheduled_end', '>', $start)->exists()) {
            throw $timeConflict;
        }

        try {
            return DB::transaction(function () use ($candidateId, $offering, $slot, $start, $end, $cvId, $data) {
                // Taking the slot IS this one UPDATE. "Still open and not started" is part of
                // it, so when two candidates book the same slot at the same moment the
                // database lets exactly one of them change the row. The other gets 0 here.
                $taken = AvailabilitySlot::whereKey($slot->id)
                    ->where('status', SlotStatus::Open)
                    ->where('starts_at', '>', now())
                    ->update(['status' => SlotStatus::Held]);

                if ($taken === 0) {
                    throw ApiError::conflict('SLOT_UNAVAILABLE', 'This slot is no longer available');
                }

                return Session::create([
                    'candidate_id' => $candidateId,
                    'recruiter_id' => $offering->recruiter_id,
                    'offering_id' => $offering->id,
                    'slot_id' => $slot->id,
                    'cv_id' => $cvId,
                    'specialization_id' => $this->profiles->candidateSpecializationId($candidateId),
                    'type' => $offering->session_type,
                    'mode' => $offering->mode,
                    'duration_minutes' => $offering->duration_minutes,
                    'price_minor' => $offering->price_minor,
                    'currency' => $offering->currency,
                    'scheduled_start' => $start,
                    'scheduled_end' => $end,
                    'status' => SessionStatus::PendingPayment,
                    'candidate_notes' => $data['candidateNotes'] ?? null,
                    'hold_expires_at' => now()->addMinutes(Session::HOLD_MINUTES),
                ]);
            });
        } catch (QueryException $e) {
            // The candidate sent two bookings for overlapping times at the same instant, and
            // both passed the check above. The database constraint refused the second one,
            // and the transaction rolled its slot back to open.
            if ($e->getCode() === self::EXCLUSION_VIOLATION) {
                throw $timeConflict;
            }

            throw $e;
        }
    }
}
