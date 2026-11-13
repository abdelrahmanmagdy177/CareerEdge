<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\BillableSession;
use App\Modules\Scheduling\Contracts\SessionBilling;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Support\Facades\DB;

final class SessionBillingService implements SessionBilling
{
    public function find(string $sessionId): ?BillableSession
    {
        $session = Session::find($sessionId);

        return $session === null ? null : new BillableSession(
            $session->id,
            $session->candidate_id,
            $session->recruiter_id,
            $session->status,
            $session->price(),
            $session->hold_expires_at,
            visibleToRecruiter: $session->confirmed_at !== null,
        );
    }

    public function confirmPaid(string $sessionId): bool
    {
        return DB::transaction(function () use ($sessionId) {
            // "Still waiting for payment" is part of the UPDATE. If the hold-expiry job
            // cancelled the session a moment ago, this changes nothing and we say so.
            $confirmed = Session::whereKey($sessionId)
                ->where('status', SessionStatus::PendingPayment)
                ->update([
                    'status' => SessionStatus::Confirmed,
                    'confirmed_at' => now(),
                    'hold_expires_at' => null,
                ]);

            if ($confirmed === 0) {
                return false;
            }

            AvailabilitySlot::whereKey(Session::whereKey($sessionId)->value('slot_id'))
                ->update(['status' => SlotStatus::Booked]);

            return true;
        });
    }
}
