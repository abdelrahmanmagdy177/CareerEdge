<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Profiles\Contracts\RecruiterStats;
use App\Modules\Scheduling\Broadcasts\SessionStatusChanged;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Events\SessionCancelled;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Session;
use App\Support\Http\ApiError;
use App\Support\Money;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * Business rule 5, the cancellation policy:
 *
 *   candidate, more than 24 hours before the start → 100% back
 *   candidate, 24 hours or less before the start   → 50% back
 *   recruiter, any time before the start           → 100% back, and it counts against them
 *   nobody, once the session's start time has come → not possible
 *
 * A session that was never paid can be dropped by the candidate with nothing to refund.
 */
final class SessionCanceller
{
    public const FULL_REFUND_HOURS = 24;

    public function __construct(private readonly RecruiterStats $recruiterStats) {}

    /**
     * What cancelling right now would mean, without doing it. The same method decides the
     * real cancellation, so the preview the user saw is what they get.
     *
     * @return array{refundPercent: int, refundAmount: array{amountMinor: int, currency: string}, policy: string}
     */
    public function quote(Session $session, string $userId): array
    {
        [$percent, $policy] = $this->policyFor($session, $userId);

        return [
            'refundPercent' => $percent,
            // Whole piasters. intdiv rounds an odd half-piaster down, in the platform's favour by at most 1.
            'refundAmount' => (new Money($policy === 'unpaid' ? 0 : intdiv($session->price_minor * $percent, 100), $session->currency))->toArray(),
            'policy' => $policy,
        ];
    }

    public function cancel(Session $session, string $userId, string $reason): void
    {
        [$percent, $policy] = $this->policyFor($session, $userId);
        $byRecruiter = $userId === $session->recruiter_id;
        $wasPaid = $session->status === SessionStatus::Confirmed;

        $cancelled = DB::transaction(function () use ($session, $byRecruiter, $wasPaid, $reason) {
            // The expected status and "not started yet" are part of the UPDATE, so a cancel
            // that races with the payment arriving, or with the clock reaching the start
            // time, changes nothing instead of cancelling the wrong thing.
            $cancelled = Session::whereKey($session->id)
                ->where('status', $session->status)
                ->when($wasPaid, fn ($q) => $q->where('scheduled_start', '>', now()))
                ->update([
                    'status' => SessionStatus::Cancelled,
                    'cancelled_by' => $byRecruiter ? 'recruiter' : 'candidate',
                    'cancel_reason' => $reason,
                    'hold_expires_at' => null,
                ]);

            if ($cancelled === 0) {
                return false;
            }

            if ($session->slot_id !== null) {
                $byRecruiter
                    // The recruiter said they cannot make it, so the time must not go back on sale.
                    ? AvailabilitySlot::whereKey($session->slot_id)->delete()
                    // The candidate stepped back, so someone else can have the time.
                    : AvailabilitySlot::whereKey($session->slot_id)->update(['status' => SlotStatus::Open]);
            }

            return true;
        });

        if (! $cancelled) {
            throw ApiError::conflict('INVALID_SESSION_STATE', 'This session can no longer be cancelled');
        }

        if ($byRecruiter) {
            $this->recruiterStats->recordCancellation($session->recruiter_id);
        }

        event(new SessionCancelled($session->id, $policy === 'unpaid' ? 0 : $percent));
        Realtime::send(new SessionStatusChanged($session->id, SessionStatus::Cancelled));
    }

    /**
     * @return array{0: int, 1: string} refund percent and the policy's name in the contract
     */
    private function policyFor(Session $session, string $userId): array
    {
        $notCancellable = ApiError::conflict('INVALID_SESSION_STATE', 'This session can no longer be cancelled');

        if ($session->status === SessionStatus::PendingPayment) {
            // Only the candidate ever sees an unpaid session.
            return [0, 'unpaid'];
        }

        if ($session->status !== SessionStatus::Confirmed || now()->greaterThanOrEqualTo($session->scheduled_start)) {
            throw $notCancellable;
        }

        if ($userId === $session->recruiter_id) {
            return [100, 'recruiter'];
        }

        // "More than 24 hours": at exactly 24 hours the late rule already applies.
        return now()->addHours(self::FULL_REFUND_HOURS)->lessThan($session->scheduled_start)
            ? [100, 'candidate_early']
            : [50, 'candidate_late'];
    }
}
