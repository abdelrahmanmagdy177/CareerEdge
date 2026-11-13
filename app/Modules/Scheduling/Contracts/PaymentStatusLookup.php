<?php

namespace App\Modules\Scheduling\Contracts;

/**
 * Fills the `paymentStatus` field of a session. Scheduling owns this interface and the
 * Payments module implements it. Until then the default says "no payment" for everything.
 */
interface PaymentStatusLookup
{
    /**
     * @param  list<string>  $sessionIds
     * @return array<string, string> payment status keyed by session id. Sessions without a payment are absent.
     */
    public function statusesFor(array $sessionIds): array;
}
