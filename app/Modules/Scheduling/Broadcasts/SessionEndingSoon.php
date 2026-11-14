<?php

namespace App\Modules\Scheduling\Broadcasts;

use App\Support\Http\ApiDate;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Live event `session.ending-soon`, sent once about 5 minutes before the scheduled end. */
final class SessionEndingSoon implements ShouldBroadcastNow
{
    public function __construct(public readonly string $sessionId, public readonly CarbonInterface $endsAt) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("session.{$this->sessionId}");
    }

    public function broadcastAs(): string
    {
        return 'session.ending-soon';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['sessionId' => $this->sessionId, 'endsAt' => ApiDate::format($this->endsAt)];
    }
}
