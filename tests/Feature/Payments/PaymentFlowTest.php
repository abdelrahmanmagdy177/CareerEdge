<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use BooksSessions, CreatesRecruiters, RefreshDatabase;

    private string $candidate;

    private string $candidateEmail;

    /** @var array<string, mixed> */
    private array $setup;

    /** @var array<string, mixed> */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00:00');
        $auth = $this->register(['fullName' => 'Ahmed Hassan']);
        $this->candidate = $auth['accessToken'];
        $this->candidateEmail = $auth['user']['email'];
        $this->setup = $this->bookable();
        $this->session = $this->book($this->candidate, $this->setup['body'])->assertCreated()->json();
    }

    private function sessionNow(?string $token = null): array
    {
        return $this->api('GET', "/sessions/{$this->session['id']}", token: $token ?? $this->candidate)->assertOk()->json();
    }

    private function startCheckout(array $body = []): string
    {
        return $this->checkout($this->candidate, $this->session['id'], $body)->assertOk()->json('paymentId');
    }

    public function test_checkout_opens_a_payment_and_returns_the_page_to_pay_on(): void
    {
        $response = $this->checkout($this->candidate, $this->session['id'])->assertOk();

        $this->assertSame(['paymentId', 'paymentUrl', 'expiresAt'], array_keys($response->json()));
        // Paying is possible exactly as long as the slot is held.
        $this->assertSame($this->session['holdExpiresAt'], $response->json('expiresAt'));
        $this->assertStringContainsString('/fake-gateway/checkout/fake_', $response->json('paymentUrl'));

        $payment = Payment::findOrFail($response->json('paymentId'));
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(45000, $payment->amount_minor);
        $this->assertSame('pending', $this->sessionNow()['paymentStatus']);
        // Starting to pay does not confirm anything yet.
        $this->assertSame('pending_payment', $this->sessionNow()['status']);
    }

    public function test_pressing_pay_twice_gives_the_same_payment(): void
    {
        $first = $this->checkout($this->candidate, $this->session['id'])->assertOk()->json();
        // A different idempotency key, so this is a real second request and not a replay.
        $second = $this->checkout($this->candidate, $this->session['id'])->assertOk()->json();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_needs_an_idempotency_key_and_replays_with_the_same_one(): void
    {
        $uri = "/sessions/{$this->session['id']}/checkout";
        $this->assertProblem($this->api('POST', $uri, token: $this->candidate), 400, 'VALIDATION_FAILED');

        $key = (string) Str::uuid();
        $first = $this->checkout($this->candidate, $this->session['id'], key: $key)->assertOk();
        $second = $this->checkout($this->candidate, $this->session['id'], key: $key)->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame('true', $second->headers->get('Idempotent-Replayed'));
    }

    public function test_the_database_itself_refuses_two_open_payments_for_one_session(): void
    {
        $payment = Payment::findOrFail($this->startCheckout());
        $this->expectException(UniqueConstraintViolationException::class);

        $payment->replicate()->fill(['gateway_reference' => 'fake_other'])->save();
    }

    public function test_a_successful_payment_confirms_the_session_and_books_the_slot(): void
    {
        $paymentId = $this->startCheckout();

        $this->travel(2)->minutes();
        $this->webhook($paymentId)->assertOk()->assertExactJson(['received' => true]);

        $session = $this->sessionNow();
        $this->assertSame('confirmed', $session['status']);
        $this->assertSame('held', $session['paymentStatus']);
        $this->assertNull($session['holdExpiresAt']);
        $this->assertTrue($session['chatOpen']);
        $this->assertSame(SlotStatus::Booked, AvailabilitySlot::findOrFail($this->setup['slot']['id'])->status);

        $this->api('GET', "/sessions/{$this->session['id']}/payment", token: $this->candidate)->assertOk()->assertExactJson([
            'id' => $paymentId,
            'sessionId' => $this->session['id'],
            'amount' => ['amountMinor' => 45000, 'currency' => 'EGP'],
            'refunded' => ['amountMinor' => 0, 'currency' => 'EGP'],
            'status' => 'held',
            'heldAt' => '2026-11-15T10:02:00Z',
            'releaseScheduledAt' => null,
            'releasedAt' => null,
            'refundedAt' => null,
        ]);
    }

    public function test_the_same_webhook_arriving_again_changes_nothing(): void
    {
        $paymentId = $this->startCheckout();
        $this->webhook($paymentId)->assertOk();
        $heldAt = Payment::findOrFail($paymentId)->held_at;

        $this->travel(5)->minutes();
        $this->webhook($paymentId)->assertOk();
        // Even a late "failed" for a payment that already succeeded is ignored.
        $this->webhook($paymentId, 'failed')->assertOk();

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame(PaymentStatus::Held, $payment->status);
        $this->assertEquals($heldAt, $payment->held_at);
        $this->assertSame('confirmed', Session::findOrFail($this->session['id'])->status->value);
    }

    public function test_a_webhook_with_a_bad_signature_is_refused_and_changes_nothing(): void
    {
        $paymentId = $this->startCheckout();

        foreach (['', 'wrong', str_repeat('a', 64)] as $signature) {
            $this->assertProblem($this->webhook($paymentId, signature: $signature), 401, 'INVALID_SIGNATURE');
        }

        $this->assertSame(PaymentStatus::Pending, Payment::findOrFail($paymentId)->status);
        $this->assertSame('pending_payment', Session::findOrFail($this->session['id'])->status->value);
    }

    public function test_a_valid_signature_cannot_be_reused_for_a_different_body(): void
    {
        $paymentId = $this->startCheckout();
        $payment = Payment::findOrFail($paymentId);
        // Signed for a "failed" event...
        $signature = $this->fakeGateway()->sign(json_encode([
            'reference' => $payment->gateway_reference, 'status' => 'failed', 'amountMinor' => 45000, 'currency' => 'EGP',
        ]));

        // ...but sent with a "succeeded" body.
        $this->assertProblem($this->webhook($paymentId, 'succeeded', signature: $signature), 401, 'INVALID_SIGNATURE');
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_webhook_for_an_unknown_payment_is_acknowledged_and_ignored(): void
    {
        $paymentId = $this->startCheckout();

        $this->webhook($paymentId, overrides: ['reference' => 'fake_does_not_exist'])->assertOk();

        $this->assertSame(PaymentStatus::Pending, Payment::findOrFail($paymentId)->status);
    }

    public function test_a_webhook_for_a_provider_that_is_not_configured_is_a_404(): void
    {
        $response = $this->asFreshClient()->postJson('/api/v1/payments/webhooks/paymob', ['anything' => true]);

        $this->assertProblem($response, 404, 'NOT_FOUND');
    }

    public function test_a_declined_payment_leaves_the_session_waiting_and_the_candidate_can_try_again(): void
    {
        $declined = $this->startCheckout();
        $this->webhook($declined, 'failed')->assertOk();

        $this->assertSame(PaymentStatus::Failed, Payment::findOrFail($declined)->status);
        $this->assertSame('pending_payment', $this->sessionNow()['status']);
        $this->assertSame('failed', $this->sessionNow()['paymentStatus']);

        // A new attempt is a new payment.
        $retry = $this->startCheckout();
        $this->assertNotSame($declined, $retry);
        $this->webhook($retry)->assertOk();

        $this->assertSame('confirmed', $this->sessionNow()['status']);
        $this->assertSame('held', $this->sessionNow()['paymentStatus']);
    }

    public function test_money_that_is_not_the_asked_price_does_not_confirm_the_session(): void
    {
        $paymentId = $this->startCheckout();

        $this->webhook($paymentId, overrides: ['amountMinor' => 100])->assertOk();

        $this->assertSame(PaymentStatus::Failed, Payment::findOrFail($paymentId)->status);
        $this->assertSame('pending_payment', Session::findOrFail($this->session['id'])->status->value);
    }

    public function test_when_the_hold_runs_out_the_session_is_cancelled_and_the_slot_is_free_again(): void
    {
        $paymentId = $this->startCheckout();

        // One second before the 15 minutes are up, nothing happens.
        $this->travelTo('2026-11-15 10:14:59');
        $this->artisan('sessions:expire-holds')->assertSuccessful();
        $this->assertSame('pending_payment', Session::findOrFail($this->session['id'])->status->value);

        $this->travelTo('2026-11-15 10:15:00');
        $this->artisan('sessions:expire-holds')->assertSuccessful();

        $session = Session::findOrFail($this->session['id']);
        $this->assertSame('cancelled', $session->status->value);
        $this->assertSame('system', $session->cancelled_by);
        $this->assertNull($session->hold_expires_at);
        $this->assertSame(SlotStatus::Open, AvailabilitySlot::findOrFail($this->setup['slot']['id'])->status);
        // The payment that was started is closed too.
        $this->assertSame(PaymentStatus::Failed, Payment::findOrFail($paymentId)->status);

        // Someone else can now book the slot.
        $this->book($this->register()['accessToken'], $this->setup['body'])->assertCreated();
    }

    public function test_the_expiry_job_leaves_paid_sessions_alone(): void
    {
        $this->webhook($this->startCheckout())->assertOk();

        $this->travel(1)->hours();
        $this->artisan('sessions:expire-holds')->assertSuccessful();

        $this->assertSame('confirmed', Session::findOrFail($this->session['id'])->status->value);
        $this->assertSame(SlotStatus::Booked, AvailabilitySlot::findOrFail($this->setup['slot']['id'])->status);
    }

    public function test_money_that_arrives_after_the_session_was_cancelled_is_refunded_in_full(): void
    {
        $paymentId = $this->startCheckout();
        $this->travel(16)->minutes();
        $this->artisan('sessions:expire-holds')->assertSuccessful();
        // The slot went back on sale and another candidate took it.
        $this->book($this->register()['accessToken'], $this->setup['body'])->assertCreated();

        // A new pending payment cannot exist for the cancelled session, so replay the original
        // gateway event by hand: the candidate paid at the last second.
        Payment::whereKey($paymentId)->update(['status' => PaymentStatus::Pending]);
        $this->webhook($paymentId)->assertOk();

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(45000, $payment->refunded_minor);
        $this->assertNotNull($payment->refunded_at);
        $this->assertSame([['reference' => $payment->gateway_reference, 'amountMinor' => 45000]], $this->fakeGateway()->refunds);
        // The first candidate did not get the session back.
        $this->assertSame('cancelled', Session::findOrFail($this->session['id'])->status->value);
    }

    public function test_a_payment_that_arrives_after_the_deadline_but_before_the_job_ran_still_counts(): void
    {
        $paymentId = $this->startCheckout();

        // 15 minutes passed, but the slot is still held for this candidate: nobody lost anything.
        $this->travel(15)->minutes();
        $this->webhook($paymentId)->assertOk();
        $this->artisan('sessions:expire-holds')->assertSuccessful();

        $this->assertSame('confirmed', Session::findOrFail($this->session['id'])->status->value);
        $this->assertSame(PaymentStatus::Held, Payment::findOrFail($paymentId)->status);
    }

    public function test_checkout_is_refused_once_the_session_is_paid(): void
    {
        $this->webhook($this->startCheckout())->assertOk();

        $this->assertProblem($this->checkout($this->candidate, $this->session['id']), 409, 'INVALID_SESSION_STATE');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_is_refused_once_the_15_minutes_are_over_even_before_the_expiry_job_ran(): void
    {
        $this->travel(15)->minutes();
        // The access token expired with the time jump, so log in again as the same candidate.
        $token = $this->api('POST', '/auth/login', ['email' => $this->candidateEmail, 'password' => self::PASSWORD])->json('accessToken');

        $this->assertProblem($this->checkout($token, $this->session['id']), 409, 'INVALID_SESSION_STATE');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_only_the_candidate_who_booked_can_pay(): void
    {
        $stranger = $this->register()['accessToken'];
        $recruiter = $this->setup['recruiter']['accessToken'];

        $this->assertProblem($this->checkout($stranger, $this->session['id']), 404, 'NOT_FOUND');
        $this->assertProblem($this->checkout($recruiter, $this->session['id']), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->checkout($this->candidate, '01a1c737-b065-7130-8d08-f913763e916c'), 404, 'NOT_FOUND');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_who_can_read_the_payment(): void
    {
        $uri = "/sessions/{$this->session['id']}/payment";
        $recruiter = $this->setup['recruiter']['accessToken'];
        $stranger = $this->register()['accessToken'];

        // No payment yet.
        $this->assertProblem($this->api('GET', $uri, token: $this->candidate), 404, 'NOT_FOUND');

        $paymentId = $this->startCheckout();
        $this->api('GET', $uri, token: $this->candidate)->assertOk()->assertJson(['status' => 'pending']);
        // The recruiter does not see an unpaid attempt.
        $this->assertProblem($this->api('GET', $uri, token: $recruiter), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', $uri, token: $stranger), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', $uri), 401, 'TOKEN_INVALID');

        $this->webhook($paymentId)->assertOk();
        $this->api('GET', $uri, token: $recruiter)->assertOk()->assertJson(['status' => 'held']);
        $this->assertProblem($this->api('GET', $uri, token: $stranger), 404, 'NOT_FOUND');
    }

    public function test_the_return_url_can_be_a_web_link_or_an_app_deep_link_but_not_a_script(): void
    {
        foreach (['javascript:alert(1)', 'not a url', 'data:text/html,hi'] as $bad) {
            $response = $this->checkout($this->candidate, $this->session['id'], ['returnUrl' => $bad]);
            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame(['returnUrl'], array_keys($response->json('errors')));
        }

        $this->checkout($this->candidate, $this->session['id'], ['returnUrl' => 'careeredge://payment/done'])->assertOk();
        $this->assertSame('careeredge://payment/done', Payment::firstOrFail()->return_url);
    }

    public function test_the_fake_payment_page_pays_and_sends_the_user_back_to_the_app(): void
    {
        $paymentId = $this->startCheckout(['returnUrl' => 'careeredge://payment/done']);
        $url = Payment::findOrFail($paymentId)->checkout_url;

        $this->asFreshClient()->get($url)->assertOk()->assertSee('450.00 EGP')->assertSee('Pay');

        $this->asFreshClient()->post($url, ['outcome' => 'succeeded'])
            ->assertRedirect('careeredge://payment/done?payment=success');

        $this->assertSame('confirmed', $this->sessionNow()['status']);
    }

    public function test_declining_on_the_fake_payment_page_fails_the_payment(): void
    {
        $paymentId = $this->startCheckout();
        $url = Payment::findOrFail($paymentId)->checkout_url;

        $this->asFreshClient()->post($url, ['outcome' => 'failed'])->assertOk()->assertSee('Payment failed');

        $this->assertSame(PaymentStatus::Failed, Payment::findOrFail($paymentId)->status);
        $this->assertSame('pending_payment', $this->sessionNow()['status']);
        $this->asFreshClient()->get('/fake-gateway/checkout/fake_unknown')->assertNotFound();
    }

    public function test_the_recruiter_can_read_the_sessions_cv_only_once_it_is_paid_and_until_7_days_after(): void
    {
        $file = UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.7\ncv");
        $cvId = $this->asFreshClient($this->candidate)
            ->post('/api/v1/me/cvs', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $other = $this->bookable('Mona Adel', start: '+2 days 10:00', end: '+2 days 11:00');
        $session = $this->book($this->candidate, [...$other['body'], 'cvId' => $cvId])->assertCreated()->json();
        $uri = "/cvs/$cvId/download-url";
        $recruiterEmail = $other['recruiter']['user']['email'];
        $login = fn () => $this->api('POST', '/auth/login', ['email' => $recruiterEmail, 'password' => self::PASSWORD])->json('accessToken');

        // Booked but not paid: no access.
        $this->assertProblem($this->api('GET', $uri, token: $other['recruiter']['accessToken']), 404, 'NOT_FOUND');

        $this->webhook($this->checkout($this->candidate, $session['id'])->json('paymentId'))->assertOk();
        $this->api('GET', $uri, token: $other['recruiter']['accessToken'])->assertOk();
        // A recruiter of a different session still has none.
        $this->assertProblem($this->api('GET', $uri, token: $this->setup['recruiter']['accessToken']), 404, 'NOT_FOUND');

        // The session ends on 2026-11-17 at 10:45. Six days later: still allowed.
        $this->travelTo('2026-11-23 10:00:00');
        $this->api('GET', $uri, token: $login())->assertOk();

        // More than seven days later: not any more.
        $this->travelTo('2026-11-24 10:46:00');
        $this->assertProblem($this->api('GET', $uri, token: $login()), 404, 'NOT_FOUND');
    }
}
