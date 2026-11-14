<?php

namespace App\Modules\Scheduling\Broadcasts;

use App\Modules\Scheduling\Enums\SessionStatus;
use App\Support\Http\ApiDate;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Live event `session.status-changed` on the session's private channel (docs/PLAN.md §5).
 *
 * ShouldBroadcastNow sends it during the request. The plain ShouldBroadcast would put it on
 * the queue, and then nothing is delivered unless a queue worker is running.
 */
final class SessionStatusChanged implements ShouldBroadcastNow
{
    public function __construct(public readonly string $sessionId, public readonly SessionStatus $status) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("session.{$this->sessionId}");
    }

    public function broadcastAs(): string
    {
        return 'session.status-changed';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['sessionId' => $this->sessionId, 'status' => $this->status->value, 'at' => ApiDate::format(now())];
    }
}
