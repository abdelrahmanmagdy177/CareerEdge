<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Modules\Messaging\Broadcasts\MessageSent;
use App\Modules\Messaging\Models\Message;
use App\Modules\Scheduling\Contracts\SessionAccess;
use App\Modules\Scheduling\Contracts\SessionView;
use App\Support\Http\ApiError;
use App\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MessageController
{
    public function __construct(private readonly SessionAccess $sessions) {}

    /**
     * GET /sessions/{session}/messages?before=&limit=
     *
     * Newest first. To load older messages, pass the id of the oldest one you have as `before`.
     * (A cursor instead of page numbers: new messages arriving do not shift the pages.)
     */
    public function index(Request $request, string $session): JsonResponse
    {
        $this->sessionFor($request, $session);

        $filters = $request->validate([
            'before' => ['bail', 'nullable', 'uuid'],
            'limit' => ['bail', 'nullable', 'integer', 'between:1,100'],
        ], [
            'before.*' => 'before must be a message id.',
            'limit.*' => 'limit must be between 1 and 100.',
        ]);
        $limit = (int) ($filters['limit'] ?? 50);

        // One row more than asked for: if it comes back, there are older messages left.
        $messages = Message::where('session_id', $session)
            ->when($filters['before'] ?? null, fn ($q, string $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return response()->json([
            'items' => $messages->take($limit)->map->toContract()->values(),
            'hasMore' => $messages->count() > $limit,
        ]);
    }

    /** POST /sessions/{session}/messages */
    public function store(Request $request, string $session): JsonResponse
    {
        $view = $this->sessionFor($request, $session);
        $userId = $request->user()->id;

        // Only the two people in the session write in it. An admin can read, not write.
        if (! $view->isParticipant($userId)) {
            throw ApiError::forbidden('FORBIDDEN_ROLE', 'Only the participants of a session can send messages');
        }

        $data = $request->validate([
            'clientMessageId' => ['bail', 'required', 'uuid'],
            'body' => ['bail', 'required', 'string', 'max:'.Message::MAX_LENGTH],
        ], [
            'clientMessageId.*' => 'clientMessageId is required and must be a UUID.',
            'body.*' => 'A message must have between 1 and 4000 characters.',
        ]);

        $existing = fn () => Message::where('session_id', $session)
            ->where('sender_id', $userId)
            ->where('client_message_id', $data['clientMessageId'])
            ->first();

        // The same message sent again (a retry): answer 200 with the one we already have.
        if ($already = $existing()) {
            return response()->json($already->toContract());
        }

        if (! $view->chatOpen()) {
            throw ApiError::conflict('CHAT_CLOSED', 'Messages can be sent from when the session is confirmed until it is completed');
        }

        try {
            $message = DB::transaction(fn () => Message::create([
                'session_id' => $session,
                'sender_id' => $userId,
                'client_message_id' => $data['clientMessageId'],
                'body' => $data['body'],
            ]));
        } catch (UniqueConstraintViolationException) {
            // The retry arrived at the same instant as the original and lost the race.
            return response()->json($existing()->toContract());
        }

        Realtime::send(new MessageSent($message));

        return response()->json($message->toContract(), 201);
    }

    /** The session, if this user may see it. Otherwise 404, the same as a session that does not exist. */
    private function sessionFor(Request $request, string $sessionId): SessionView
    {
        $user = $request->user();

        return $this->sessions->view($sessionId, $user->id, $user->role->value) ?? throw ApiError::notFound();
    }
}
