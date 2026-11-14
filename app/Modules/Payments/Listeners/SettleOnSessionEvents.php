<?php

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentSettlement;
use App\Modules\Scheduling\Events\SessionCancelled;
use App\Modules\Scheduling\Events\SessionCompleted;
use App\Modules\Scheduling\Events\SessionDisputed;
use App\Modules\Scheduling\Events\SessionNoShow;
use Illuminate\Events\Dispatcher;

/**
 * Everything Payments does in reaction to what happens to a session. Scheduling announces
 * the events and knows nothing about money. This is the one place the two are connected.
 */
final class SettleOnSessionEvents
{
    public function __construct(private readonly PaymentSettlement $settlement) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(SessionCancelled::class, [self::class, 'cancelled']);
        $events->listen(SessionNoShow::class, [self::class, 'noShow']);
        $events->listen(SessionCompleted::class, [self::class, 'completed']);
        $events->listen(SessionDisputed::class, [self::class, 'disputed']);
    }

    public function cancelled(SessionCancelled $event): void
    {
        // A checkout that was started but never finished is simply closed.
        Payment::where('session_id', $event->sessionId)
            ->where('status', PaymentStatus::Pending)
            ->update(['status' => PaymentStatus::Failed]);

        // Money that was already held goes back according to the cancellation policy.
        $this->settlement->refund($event->sessionId, $event->refundPercent);
    }

    /** Business rule 7: the recruiter never came, so the candidate gets everything back. */
    public function noShow(SessionNoShow $event): void
    {
        $this->settlement->refund($event->sessionId, 100);
    }

    public function completed(SessionCompleted $event): void
    {
        $this->settlement->scheduleRelease($event->sessionId);
    }

    public function disputed(SessionDisputed $event): void
    {
        $this->settlement->freeze($event->sessionId);
    }
}
