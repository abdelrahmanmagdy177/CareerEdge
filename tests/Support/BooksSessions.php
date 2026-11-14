<?php

namespace Tests\Support;

use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Gateways\PaymentGateway;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the booking and payment flow, all through the real API.
 * Use together with CreatesRecruiters.
 */
trait BooksSessions
{
    /**
     * POST /sessions. A fresh Idempotency-Key is used unless one is given.
     *
     * @param  array<string, mixed>  $body
     */
    protected function book(string $candidateToken, array $body, ?string $key = null): TestResponse
    {
        return $this->api('POST', '/sessions', $body, $candidateToken, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /**
     * A recruiter with one offering and one free slot, ready to be booked.
     *
     * @param  array<string, mixed>  $offering
     * @return array{recruiter: array<string, mixed>, offering: array<string, mixed>, slot: array<string, mixed>, body: array<string, string>}
     */
    protected function bookable(string $name = 'Sara Mostafa', array $offering = [], string $start = '+1 day 16:00', string $end = '+1 day 17:00'): array
    {
        $recruiter = $this->recruiter($name);
        $published = $this->offer($recruiter['accessToken'], $offering);
        $slot = $this->slot($recruiter['accessToken'], $start, $end);

        return [
            'recruiter' => $recruiter,
            'offering' => $published,
            'slot' => $slot,
            'body' => ['offeringId' => $published['id'], 'slotId' => $slot['id']],
        ];
    }

    /** POST /sessions/{id}/checkout */
    protected function checkout(string $candidateToken, string $sessionId, array $body = [], ?string $key = null): TestResponse
    {
        return $this->api('POST', "/sessions/$sessionId/checkout", $body, $candidateToken, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /**
     * Sends the fake gateway's webhook, signed the way the gateway signs it.
     *
     * @param  array<string, mixed>  $overrides  to send a wrong amount, for example
     */
    protected function webhook(string $paymentId, string $status = 'succeeded', array $overrides = [], ?string $signature = null): TestResponse
    {
        $payment = Payment::findOrFail($paymentId);
        $body = json_encode([
            'reference' => $payment->gateway_reference,
            'status' => $status,
            'amountMinor' => $payment->amount_minor,
            'currency' => $payment->currency,
            ...$overrides,
        ]);

        return $this->asFreshClient()->call('POST', '/api/v1/payments/webhooks/fake', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? $this->fakeGateway()->sign($body),
        ], content: $body);
    }

    /**
     * Books and pays: the session comes back `confirmed`.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the session
     */
    protected function bookAndPay(string $candidateToken, array $body): array
    {
        $session = $this->book($candidateToken, $body)->assertCreated()->json();
        $paymentId = $this->checkout($candidateToken, $session['id'])->assertOk()->json('paymentId');
        $this->webhook($paymentId)->assertOk();

        return $this->api('GET', "/sessions/{$session['id']}", token: $candidateToken)->assertOk()->json();
    }

    protected function fakeGateway(): FakeGateway
    {
        return $this->app->make(PaymentGateway::class);
    }
}
