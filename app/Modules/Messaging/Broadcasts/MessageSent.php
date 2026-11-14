<?php

namespace App\Modules\Messaging\Broadcasts;

use App\Modules\Messaging\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Live event `message.sent` on the session's private channel. The payload is the Message itself. */
final class MessageSent implements ShouldBroadcastNow
{
    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("session.{$this->message->session_id}");
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return $this->message->toContract();
    }
}
