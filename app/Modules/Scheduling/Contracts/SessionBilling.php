<?php

namespace App\Modules\Scheduling\Contracts;

/**
 * How the Payments module talks to sessions. Payments never touches the sessions table:
 * it asks what to charge, and reports when the money has arrived.
 */
interface SessionBilling
{
    public function find(string $sessionId): ?BillableSession;

    /**
     * The money for this session is held. Moves it from pending_payment to confirmed and
     * marks its slot as booked.
     *
     * @return bool false if the session is no longer waiting for payment (for example the
     *              15-minute hold ran out and it was cancelled). The caller must then
     *              give the money back.
     */
    public function confirmPaid(string $sessionId): bool;
}
