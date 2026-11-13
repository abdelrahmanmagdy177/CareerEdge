<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Events\SessionCancelled;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Support\Facades\DB;

/**
 * Business rule 2: a slot is held for 15 minutes while the candidate pays. After that the
 * session is cancelled and the slot is open for others again.
 */
final class HoldExpirer
{
    /** @return int how many sessions were cancelled */
    public function expireDueHolds(): int
    {
        $due = Session::where('status', SessionStatus::PendingPayment)
            ->where('hold_expires_at', '<=', now())
            ->get(['id', 'slot_id']);

        $cancelled = 0;

        foreach ($due as $session) {
            $changed = DB::transaction(function () use ($session) {
                // Again the status is part of the UPDATE: if the payment arrived in this very
                // moment and confirmed the session, this changes nothing and we leave it alone.
                $changed = Session::whereKey($session->id)
                    ->where('status', SessionStatus::PendingPayment)
                    ->update([
                        'status' => SessionStatus::Cancelled,
                        'cancelled_by' => 'system',
                        'cancel_reason' => 'Payment was not completed in time.',
                        'hold_expires_at' => null,
                    ]);

                if ($changed === 1) {
                    AvailabilitySlot::whereKey($session->slot_id)
                        ->where('status', SlotStatus::Held)
                        ->update(['status' => SlotStatus::Open]);
                }

                return $changed === 1;
            });

            if ($changed) {
                $cancelled++;
                // After the transaction is saved, so listeners see the cancelled session.
                SessionCancelled::dispatch($session->id);
            }
        }

        return $cancelled;
    }
}
