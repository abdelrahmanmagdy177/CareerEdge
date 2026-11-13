<?php

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Scheduling\Events\SessionCancelled;

/**
 * When a session is cancelled, a payment that was started but never completed is closed.
 * (Money that was already held is refunded by the cancellation rules of milestone M6.)
 */
final class FailPendingPayments
{
    public function handle(SessionCancelled $event): void
    {
        Payment::where('session_id', $event->sessionId)
            ->where('status', PaymentStatus::Pending)
            ->update(['status' => PaymentStatus::Failed]);
    }
}
