<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Gateways\PaymentGateway;
use App\Modules\Payments\Gateways\WebhookEvent;
use App\Modules\Payments\Models\Payment;
use App\Modules\Scheduling\Contracts\BillableSession;
use App\Modules\Scheduling\Contracts\SessionBilling;
use App\Support\Http\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SessionBilling $sessions,
    ) {}

    /**
     * Starts paying for a session, or returns the payment that is already in progress for it.
     *
     * @return array{0: Payment, 1: BillableSession}
     */
    public function checkout(string $candidateId, string $sessionId, ?string $returnUrl): array
    {
        $session = $this->sessions->find($sessionId);
        if ($session === null || $session->candidateId !== $candidateId) {
            throw ApiError::notFound();
        }

        if (! $session->awaitsPayment()) {
            throw ApiError::conflict('INVALID_SESSION_STATE', 'This session is not waiting for payment');
        }

        $open = fn () => Payment::where('session_id', $sessionId)->where('status', PaymentStatus::Pending)->first();

        // Pressing "pay" twice must not open two payments. The second press gets the same page.
        if ($existing = $open()) {
            return [$existing, $session];
        }

        $paymentId = (string) Str::uuid7();
        $started = $this->gateway->startCheckout($paymentId, $session->price, $returnUrl);

        try {
            $payment = DB::transaction(fn () => Payment::create([
                'id' => $paymentId,
                'session_id' => $sessionId,
                'amount_minor' => $session->price->amountMinor,
                'currency' => $session->price->currency,
                'status' => PaymentStatus::Pending,
                'gateway' => $this->gateway->name(),
                'gateway_reference' => $started->reference,
                'checkout_url' => $started->url,
                'return_url' => $returnUrl,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Two checkouts at the same instant: the database kept the other one. Use it.
            $payment = $open() ?? throw ApiError::conflict('INVALID_SESSION_STATE', 'This session is not waiting for payment');
        }

        return [$payment, $session];
    }

    /**
     * Applies what the gateway told us. Safe to call any number of times for the same event:
     * gateways resend webhooks, and only the first one may change anything.
     */
    public function handleWebhook(WebhookEvent $event): void
    {
        DB::transaction(function () use ($event) {
            // lockForUpdate makes a second copy of the same webhook wait here until the first
            // one is finished, and then it sees the payment is no longer pending.
            $payment = Payment::where('gateway', $this->gateway->name())
                ->where('gateway_reference', $event->reference)
                ->lockForUpdate()
                ->first();

            if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                return;
            }

            if (! $event->succeeded) {
                $payment->update(['status' => PaymentStatus::Failed]);

                return;
            }

            if ($event->amount->amountMinor !== $payment->amount_minor || $event->amount->currency !== $payment->currency) {
                // Never confirm a session for money that is not the price we asked for.
                Log::warning('Payment webhook amount does not match', ['paymentId' => $payment->id]);
                $payment->update(['status' => PaymentStatus::Failed]);

                return;
            }

            if ($this->sessions->confirmPaid($payment->session_id)) {
                $payment->update(['status' => PaymentStatus::Held, 'held_at' => now()]);

                return;
            }

            // The money arrived, but the session is gone (the 15-minute hold ran out and the
            // slot went back on sale). The candidate must not pay for nothing: refund in full.
            $this->gateway->refund($payment->gateway_reference, $payment->amount());
            $payment->update([
                'status' => PaymentStatus::Refunded,
                'refunded_minor' => $payment->amount_minor,
                'refunded_at' => now(),
            ]);
        });
    }
}
