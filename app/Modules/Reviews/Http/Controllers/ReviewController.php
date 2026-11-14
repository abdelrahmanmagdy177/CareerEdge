<?php

namespace App\Modules\Reviews\Http\Controllers;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Profiles\Contracts\RecruiterStats;
use App\Modules\Profiles\Contracts\RecruiterVerification;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Contracts\SessionOutcomes;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Support\Http\ApiDate;
use App\Support\Http\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReviewController
{
    public function __construct(private readonly UserDirectory $users) {}

    /** POST /sessions/{session}/review: business rule 9, candidate only, completed only, once, within 14 days. */
    public function store(Request $request, string $session, SessionOutcomes $sessions, RecruiterStats $stats): JsonResponse
    {
        $finished = $sessions->forCandidate($session, $request->user()->id) ?? throw ApiError::notFound();

        $data = $request->validate([
            'rating' => ['bail', 'required', 'integer:strict', 'between:1,5'],
            'comment' => ['bail', 'nullable', 'string', 'max:1000'],
        ], [
            'rating.*' => 'Rating must be a whole number from 1 to 5.',
            'comment.*' => 'Comment must be at most 1000 characters.',
        ]);

        $alreadyReviewed = ApiError::conflict('ALREADY_REVIEWED', 'You already reviewed this session');
        if (Review::where('session_id', $session)->exists()) {
            throw $alreadyReviewed;
        }

        if ($finished->status !== SessionStatus::Completed) {
            throw ApiError::conflict('INVALID_SESSION_STATE', 'Only a completed session can be reviewed');
        }

        if ($finished->endedAt->copy()->addDays(Review::WINDOW_DAYS)->isPast()) {
            throw ApiError::conflict('REVIEW_WINDOW_CLOSED', 'A session can be reviewed for 14 days after it ends');
        }

        try {
            $review = DB::transaction(function () use ($finished, $data, $stats) {
                $review = Review::create([
                    'session_id' => $finished->id,
                    'candidate_id' => $finished->candidateId,
                    'recruiter_id' => $finished->recruiterId,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'] ?? null,
                ]);

                // Recomputed from all the reviews each time, not adjusted by hand: a running
                // average that is nudged on every insert drifts if anything is ever missed.
                $totals = Review::where('recruiter_id', $finished->recruiterId)
                    ->selectRaw('avg(rating) as average, count(*) as total')
                    ->first();
                $stats->recordRating($finished->recruiterId, (float) $totals->average, (int) $totals->total);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            // The same review sent twice at the same instant. The database kept one.
            throw $alreadyReviewed;
        }

        return response()->json($this->present(collect([$review]))[0], 201);
    }

    /** GET /recruiters/{recruiter}/reviews: newest first. */
    public function index(Request $request, string $recruiter, RecruiterVerification $recruiters): JsonResponse
    {
        // Only approved recruiters have a public page, so only they have public reviews.
        if (! $recruiters->isApproved($recruiter)) {
            throw ApiError::notFound();
        }

        $filters = $request->validate([
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'pageSize' => ['bail', 'nullable', 'integer', 'between:1,100'],
        ], ['page.*' => 'page must be 1 or more.', 'pageSize.*' => 'pageSize must be between 1 and 100.']);
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);

        $query = Review::where('recruiter_id', $recruiter);

        return response()->json([
            'items' => $this->present((clone $query)->orderByDesc('id')->forPage($page, $pageSize)->get()),
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $query->count(),
        ]);
    }

    /**
     * The `Review` schema of docs/openapi.yaml.
     *
     * @param  Collection<int, Review>  $reviews
     * @return list<array<string, mixed>>
     */
    private function present(Collection $reviews): array
    {
        $candidates = $this->users->summaries($reviews->pluck('candidate_id')->all());

        return $reviews->map(fn (Review $review) => [
            'id' => $review->id,
            'sessionId' => $review->session_id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            // Reviews are public. Only the first name is shown, to keep the candidate private.
            'candidateFirstName' => Str::before($candidates[$review->candidate_id]['fullName'], ' '),
            'createdAt' => ApiDate::format($review->created_at),
        ])->values()->all();
    }
}
