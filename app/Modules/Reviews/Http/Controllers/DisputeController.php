<?php

namespace App\Modules\Reviews\Http\Controllers;

use App\Modules\Reviews\Models\Dispute;
use App\Modules\Scheduling\Contracts\SessionOutcomes;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Support\Http\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DisputeController
{
    /** POST /sessions/{session}/dispute: the candidate, within 24 hours after a completed session ended. */
    public function store(Request $request, string $session, SessionOutcomes $sessions): JsonResponse
    {
        $finished = $sessions->forCandidate($session, $request->user()->id) ?? throw ApiError::notFound();

        $data = $request->validate(
            ['reason' => ['bail', 'required', 'string', 'min:20', 'max:2000']],
            ['reason.*' => 'Describe the problem in 20 to 2000 characters.'],
        );

        $notCompleted = ApiError::conflict('INVALID_SESSION_STATE', 'Only a completed session can be disputed, and only once');
        if ($finished->status !== SessionStatus::Completed) {
            throw $notCompleted;
        }

        if ($finished->endedAt->copy()->addHours(Dispute::WINDOW_HOURS)->isPast()) {
            throw ApiError::conflict('DISPUTE_WINDOW_CLOSED', 'A dispute can be opened for 24 hours after the session ends');
        }

        try {
            $dispute = DB::transaction(function () use ($finished, $data, $sessions, $notCompleted) {
                $dispute = Dispute::create([
                    'session_id' => $finished->id,
                    'opened_by' => $finished->candidateId,
                    'reason' => $data['reason'],
                    'status' => Dispute::OPEN,
                ]);

                // Moves the session to `disputed`, which also stops the recruiter's payout.
                // If the session is not `completed` any more, nothing is saved.
                if (! $sessions->markDisputed($finished->id)) {
                    throw $notCompleted;
                }

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            throw $notCompleted;
        }

        return response()->json($dispute->toContract(), 201);
    }
}
