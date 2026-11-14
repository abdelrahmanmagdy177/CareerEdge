<?php

namespace App\Support;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Throwable;

final class Realtime
{
    /**
     * Sends a live event to connected apps, and never lets a failure break the request.
     *
     * Live events are a convenience on top of the REST API: a message is already saved when
     * its event goes out. If the WebSocket server is down, the sender must still get their
     * 201, and the other side will see the message on their next fetch. So a failure here is
     * reported to the log and swallowed.
     */
    public static function send(ShouldBroadcast $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
