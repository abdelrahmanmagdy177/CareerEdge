<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Gateways\PaymentGateway;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * What happens to held money once the session's fate is known:
 * back to the candidate (refund), or on to the recruiter (release).
 *
 * Every method first locks the payment row and checks it is still `held`. Money moves at
 * most once per payment, whatever order or number of events arrive.
 */
final class PaymentSettlement
{
    /** Business rule: the recruiter is paid 24 hours after completion, unless the candidate disputes. */
    public const RELEASE_AFTER_HOURS = 24;

    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Gives part or all of a session's held payment back to the candidate.
     * Whatever is not refunded (the other 50% of a late cancellation) goes to the recruiter now.
     */
    public function refund(string $sessionId, int $percent): void
    {
        $this->withHeldPayment($sessionId, function (Payment $payment) use ($percent) {
            $amount = intdiv($payment->amount_minor * $percent, 100);

            if ($amount > 0) {
                $this->gateway->refund($payment->gateway_reference, $payment->amount()->withAmount($amount));
            }

            $payment->update([
                'status' => match (true) {
                    $amount === $payment->amount_minor => PaymentStatus::Refunded,
                    $amount > 0 => PaymentStatus::PartiallyRefunded,
                    default => PaymentStatus::Released,
                },
                'refunded_minor' => $amount,
                'refunded_at' => $amount > 0 ? now() : null,
                // The part that was not refunded is the recruiter's from this moment.
                'released_at' => $amount < $payment->amount_minor ? now() : null,
                'release_scheduled_at' => null,
            ]);
        });
    }

    /** The session completed: plan to pay the recruiter in 24 hours. */
    public function scheduleRelease(string $sessionId): void
    {
        $this->withHeldPayment($sessionId, fn (Payment $payment) => $payment->update([
            'release_scheduled_at' => now()->addHours(self::RELEASE_AFTER_HOURS),
        ]));
    }

    /** A dispute was opened: the payout waits for an admin's decision. */
    public function freeze(string $sessionId): void
    {
        $this->withHeldPayment($sessionId, fn (Payment $payment) => $payment->update(['release_scheduled_at' => null]));
    }

    /** Pays the recruiter now (an admin decided a dispute in their favour). */
    public function release(string $sessionId): void
    {
        $this->withHeldPayment($sessionId, fn (Payment $payment) => $payment->update([
            'status' => PaymentStatus::Released,
            'released_at' => now(),
            'release_scheduled_at' => null,
        ]));
    }

    /**
     * Releases every payment whose 24 hours are over.
     *
     * @return int how many payments were released
     */
    public function releaseDue(): int
    {
        // "Still held and still scheduled" is part of the UPDATE. A dispute opened in this very
        // moment has cleared the schedule, and that payment is then simply not matched.
        return Payment::where('status', PaymentStatus::Held)
            ->whereNotNull('release_scheduled_at')
            ->where('release_scheduled_at', '<=', now())
            ->update([
                'status' => PaymentStatus::Released,
                'released_at' => now(),
                'release_scheduled_at' => null,
            ]);
    }

    /** Runs $action on the session's payment only if it is still held, with the row locked. */
    private function withHeldPayment(string $sessionId, callable $action): void
    {
        DB::transaction(function () use ($sessionId, $action) {
            $payment = Payment::where('session_id', $sessionId)
                ->where('status', PaymentStatus::Held)
                ->lockForUpdate()
                ->first();

            if ($payment !== null) {
                $action($payment);
            }
        });
    }
}
