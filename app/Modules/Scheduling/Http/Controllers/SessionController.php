<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Http\Requests\BookSessionRequest;
use App\Modules\Scheduling\Http\Requests\ListSessionsRequest;
use App\Modules\Scheduling\Models\Session;
use App\Modules\Scheduling\Services\SessionBooker;
use App\Modules\Scheduling\Services\SessionCanceller;
use App\Modules\Scheduling\Services\SessionLifecycle;
use App\Modules\Scheduling\Services\SessionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController
{
    public function __construct(private readonly SessionPresenter $presenter) {}

    /** POST /sessions: book a slot. The session waits for payment for 15 minutes. */
    public function store(BookSessionRequest $request, SessionBooker $booker): JsonResponse
    {
        $session = $booker->book($request->user()->id, $request->validated());

        return response()->json($this->presenter->one($session), 201);
    }

    /** GET /sessions: mine, as the candidate or as the recruiter. */
    public function index(ListSessionsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);
        $scope = $filters['scope'] ?? 'all';

        $upcomingStatuses = array_values(array_filter(SessionStatus::cases(), fn (SessionStatus $s) => $s->isUpcoming()));

        $query = Session::query()
            ->visibleTo($user->id, $user->role->value)
            ->when($filters['status'] ?? null, fn ($q, array $statuses) => $q->whereIn('status', $statuses))
            // Upcoming: not finished yet AND still alive. Past: everything else.
            ->when($scope === 'upcoming', fn ($q) => $q
                ->where('scheduled_end', '>', now())
                ->whereIn('status', $upcomingStatuses))
            ->when($scope === 'past', fn ($q) => $q->where(fn ($q) => $q
                ->where('scheduled_end', '<=', now())
                ->orWhereNotIn('status', $upcomingStatuses)));

        $totalCount = (clone $query)->count();

        // Upcoming: the next one first. Past and all: the most recent first.
        $sessions = $query
            ->orderBy('scheduled_start', $scope === 'upcoming' ? 'asc' : 'desc')
            ->orderBy('id')
            ->forPage($page, $pageSize)
            ->get();

        return response()->json([
            'items' => $this->presenter->many($sessions),
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
        ]);
    }

    /** GET /sessions/{session} */
    public function show(Request $request, string $session): JsonResponse
    {
        $user = $request->user();

        // Not a participant means "not found", the same as a session that does not exist.
        $record = Session::query()->visibleTo($user->id, $user->role->value)->findOrFail($session);

        return response()->json($this->presenter->one($record));
    }

    /** POST /sessions/{session}/join: enter the session. For a voice session, also get the call pass. */
    public function join(Request $request, string $session, SessionLifecycle $lifecycle): JsonResponse
    {
        $record = $this->asParticipant($request, $session);

        $voice = $lifecycle->join($record, $request->user()->id);

        return response()->json([
            'session' => $this->presenter->one($record->refresh()),
            'voice' => $voice,
        ]);
    }

    /** POST /sessions/{session}/end: the recruiter ends a session that is in progress. */
    public function end(Request $request, string $session, SessionLifecycle $lifecycle): JsonResponse
    {
        $record = $this->asParticipant($request, $session);

        $lifecycle->end($record);

        return response()->json($this->presenter->one($record->refresh()));
    }

    /** GET /sessions/{session}/cancellation-quote: what cancelling now would refund, without cancelling. */
    public function cancellationQuote(Request $request, string $session, SessionCanceller $canceller): JsonResponse
    {
        $record = $this->asParticipant($request, $session);

        return response()->json($canceller->quote($record, $request->user()->id));
    }

    /** POST /sessions/{session}/cancel */
    public function cancel(Request $request, string $session, SessionCanceller $canceller): JsonResponse
    {
        $record = $this->asParticipant($request, $session);

        $data = $request->validate(
            ['reason' => ['bail', 'required', 'string', 'min:3', 'max:500']],
            ['reason.*' => 'Give a reason of 3 to 500 characters.'],
        );

        $canceller->cancel($record, $request->user()->id, $data['reason']);

        return response()->json($this->presenter->one($record->refresh()));
    }

    /**
     * The session, if the caller is its candidate or its recruiter. Anyone else gets 404,
     * and so does an admin: an admin can read a session but takes no part in it.
     */
    private function asParticipant(Request $request, string $sessionId): Session
    {
        $user = $request->user();
        $record = Session::query()->visibleTo($user->id, $user->role->value)->findOrFail($sessionId);

        abort_unless(in_array($user->id, [$record->candidate_id, $record->recruiter_id], true), 404);

        return $record;
    }
}
