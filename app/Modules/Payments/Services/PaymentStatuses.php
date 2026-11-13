<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\Payment;
use App\Modules\Scheduling\Contracts\PaymentStatusLookup;

/** Gives Scheduling the `paymentStatus` of sessions. A session can have several payments (a failed try, then a good one): the newest counts. */
final class PaymentStatuses implements PaymentStatusLookup
{
    public function statusesFor(array $sessionIds): array
    {
        return Payment::whereIn('session_id', $sessionIds)
            // Ids are time-ordered UUIDs, so ordering by id is ordering by creation.
            ->orderBy('id')
            ->get(['session_id', 'status'])
            // Later rows overwrite earlier ones for the same session.
            ->mapWithKeys(fn (Payment $payment) => [$payment->session_id => $payment->status->value])
            ->all();
    }
}
