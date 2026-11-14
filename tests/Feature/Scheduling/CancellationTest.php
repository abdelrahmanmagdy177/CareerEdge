<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Scheduling\Broadcasts\SessionStatusChanged;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\Support\RunsSessions;
use Tests\TestCase;

/**
 * The session in these tests starts on 2026-11-18 at 16:00 and costs 450 EGP.
 * "Now" starts at 2026-11-15 10:00, three days earlier.
 */
class CancellationTest extends TestCase
{
    use BooksSessions, CreatesRecruiters, RefreshDatabase, RunsSessions;

    /** @var array<string, mixed> */
    private array $candidate;

    /** @var array<string, mixed> */
    private array $setup;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->candidate = $this->register(['fullName' => 'Ahmed Hassan']);
        $this->setup = $this->bookable(start: '+3 days 16:00', end: '+3 days 17:00');
        $this->sessionId = $this->bookAndPay($this->candidate['accessToken'], $this->setup['body'])['id'];
    }

    private function quote(array $who): TestResponse
    {
        return $this->api('GET', "/sessions/{$this->sessionId}/cancellation-quote", token: $this->tokenOf($who));
    }

    private function cancel(array $who, string $reason = 'Something came up.'): TestResponse
    {
        return $this->api('POST', "/sessions/{$this->sessionId}/cancel", ['reason' => $reason], $this->tokenOf($who));
    }

    private function recruiter(): array
    {
        return $this->setup['recruiter'];
    }

    /** @return array{0: string, 1: int, 2: string, 3: int} when, percent, policy, refund */
    public static function candidateTimes(): array
    {
        return [
            'three days before' => ['2026-11-15 10:00:00', 100, 'candidate_early', 45000],
            'one second more than 24 hours before' => ['2026-11-17 15:59:59', 100, 'candidate_early', 45000],
            'exactly 24 hours before' => ['2026-11-17 16:00:00', 50, 'candidate_late', 22500],
            'one hour before' => ['2026-11-18 15:00:00', 50, 'candidate_late', 22500],
            'one second before the start' => ['2026-11-18 15:59:59', 50, 'candidate_late', 22500],
        ];
    }

    #[DataProvider('candidateTimes')]
    public function test_the_quote_tells_the_candidate_what_they_would_get_back(string $when, int $percent, string $policy, int $refund): void
    {
        $this->travelTo($when);

        $this->quote($this->candidate)->assertOk()->assertExactJson([
            'refundPercent' => $percent,
            'refundAmount' => ['amountMinor' => $refund, 'currency' => 'EGP'],
            'policy' => $policy,
        ]);

        // Asking changes nothing.
        $this->assertSame(SessionStatus::Confirmed, Session::findOrFail($this->sessionId)->status);
        $this->assertSame(PaymentStatus::Held, $this->paymentOf($this->sessionId)->status);
    }

    #[DataProvider('candidateTimes')]
    public function test_cancelling_gives_the_candidate_exactly_what_the_quote_said(string $when, int $percent, string $policy, int $refund): void
    {
        $this->travelTo($when);

        $this->cancel($this->candidate, 'I got another offer.')->assertOk()->assertJson([
            'status' => 'cancelled',
            'cancelledBy' => 'candidate',
            'cancelReason' => 'I got another offer.',
            'chatOpen' => false,
            'paymentStatus' => $percent === 100 ? 'refunded' : 'partially_refunded',
        ]);

        $payment = $this->paymentOf($this->sessionId);
        $this->assertSame($refund, $payment->refunded_minor);
        $this->assertNotNull($payment->refunded_at);
        // What the candidate does not get back is the recruiter's, right away.
        $this->assertSame($percent === 100, $payment->released_at === null);
        // The gateway was told to send back exactly that amount, once.
        $this->assertSame([['reference' => $payment->gateway_reference, 'amountMinor' => $refund]], $this->fakeGateway()->refunds);
    }

    public function test_the_recruiter_cancelling_always_refunds_in_full_and_counts_against_them(): void
    {
        // One hour before: a candidate would get 50% here.
        $this->travelTo('2026-11-18 15:00:00');

        $this->quote($this->recruiter())->assertOk()->assertExactJson([
            'refundPercent' => 100,
            'refundAmount' => ['amountMinor' => 45000, 'currency' => 'EGP'],
            'policy' => 'recruiter',
        ]);

        $this->cancel($this->recruiter(), 'I am ill today.')->assertOk()
            ->assertJson(['status' => 'cancelled', 'cancelledBy' => 'recruiter', 'paymentStatus' => 'refunded']);

        $this->assertSame(45000, $this->paymentOf($this->sessionId)->refunded_minor);
        $this->api('GET', '/me/recruiter-profile', token: $this->tokenOf($this->recruiter()))->assertJson(['cancellationCount' => 1]);
    }

    public function test_a_candidate_cancelling_does_not_count_against_the_recruiter(): void
    {
        $this->cancel($this->candidate)->assertOk();

        $this->api('GET', '/me/recruiter-profile', token: $this->tokenOf($this->recruiter()))->assertJson(['cancellationCount' => 0]);
    }

    public function test_when_the_candidate_cancels_the_slot_goes_back_on_sale(): void
    {
        $this->cancel($this->candidate)->assertOk();

        $this->assertSame(SlotStatus::Open, AvailabilitySlot::findOrFail($this->setup['slot']['id'])->status);
        $this->book($this->register()['accessToken'], $this->setup['body'])->assertCreated();
    }

    public function test_when_the_recruiter_cancels_the_slot_is_removed_not_resold(): void
    {
        $this->cancel($this->recruiter())->assertOk();

        $this->assertNull(AvailabilitySlot::find($this->setup['slot']['id']));
        // The cancelled session is still there, with its times.
        $session = Session::findOrFail($this->sessionId);
        $this->assertNull($session->slot_id);
        $this->assertSame('2026-11-18 16:00:00', $session->scheduled_start->format('Y-m-d H:i:s'));
    }

    public function test_a_candidate_can_cancel_again_freely_after_being_cancelled_on(): void
    {
        $this->cancel($this->candidate)->assertOk();

        // The cancelled session no longer blocks the candidate's calendar at that time.
        $other = $this->bookable('Mona Adel', start: '+3 days 16:00', end: '+3 days 17:00');
        $this->book($this->tokenOf($this->candidate), $other['body'])->assertCreated();
    }

    public function test_an_unpaid_booking_can_be_dropped_with_nothing_to_refund(): void
    {
        $other = $this->bookable('Mona Adel', start: '+5 days 10:00', end: '+5 days 11:00');
        $token = $this->tokenOf($this->candidate);
        $unpaid = $this->book($token, $other['body'])->assertCreated()->json('id');
        $paymentId = $this->checkout($token, $unpaid)->json('paymentId');

        $this->api('GET', "/sessions/$unpaid/cancellation-quote", token: $token)->assertOk()->assertExactJson([
            'refundPercent' => 0,
            'refundAmount' => ['amountMinor' => 0, 'currency' => 'EGP'],
            'policy' => 'unpaid',
        ]);

        $this->api('POST', "/sessions/$unpaid/cancel", ['reason' => 'Changed my mind.'], $token)
            ->assertOk()
            ->assertJson(['status' => 'cancelled', 'cancelledBy' => 'candidate', 'holdExpiresAt' => null, 'paymentStatus' => 'failed']);

        $this->assertSame(SlotStatus::Open, AvailabilitySlot::findOrFail($other['slot']['id'])->status);
        $this->assertSame(PaymentStatus::Failed, $this->paymentOf($unpaid)->status);
        $this->assertSame([], $this->fakeGateway()->refunds);
        // A late "paid" for that cancelled booking is refunded, not accepted.
        \App\Modules\Payments\Models\Payment::whereKey($paymentId)->update(['status' => PaymentStatus::Pending]);
        $this->webhook($paymentId)->assertOk();
        $this->assertSame(PaymentStatus::Refunded, $this->paymentOf($unpaid)->status);
    }

    public function test_a_half_piaster_is_rounded_down_never_up(): void
    {
        // 450.01 EGP. Half of 45001 piasters is 22500.5, and money has no half piasters.
        Session::whereKey($this->sessionId)->update(['price_minor' => 45001]);
        \App\Modules\Payments\Models\Payment::where('session_id', $this->sessionId)->update(['amount_minor' => 45001]);
        $this->travelTo('2026-11-18 15:00:00');

        $this->quote($this->candidate)->assertJson(['refundAmount' => ['amountMinor' => 22500]]);
        $this->cancel($this->candidate)->assertOk();

        $this->assertSame(22500, $this->paymentOf($this->sessionId)->refunded_minor);
    }

    public function test_once_the_start_time_has_come_nobody_can_cancel(): void
    {
        $this->travelTo('2026-11-18 16:00:00');

        foreach ([$this->candidate, $this->recruiter()] as $who) {
            $this->assertProblem($this->quote($who), 409, 'INVALID_SESSION_STATE');
            $this->assertProblem($this->cancel($who), 409, 'INVALID_SESSION_STATE');
        }

        $this->assertSame(SessionStatus::Confirmed, Session::findOrFail($this->sessionId)->status);
        $this->assertSame(PaymentStatus::Held, $this->paymentOf($this->sessionId)->status);
    }

    #[DataProvider('finishedStatuses')]
    public function test_a_session_that_started_or_is_over_cannot_be_cancelled(SessionStatus $status): void
    {
        Session::whereKey($this->sessionId)->update(['status' => $status]);

        $this->assertProblem($this->quote($this->candidate), 409, 'INVALID_SESSION_STATE');
        $this->assertProblem($this->cancel($this->candidate), 409, 'INVALID_SESSION_STATE');
        $this->assertSame([], $this->fakeGateway()->refunds);
    }

    /** @return array<string, array{SessionStatus}> */
    public static function finishedStatuses(): array
    {
        return [
            'in progress' => [SessionStatus::InProgress],
            'completed' => [SessionStatus::Completed],
            'no show' => [SessionStatus::NoShow],
            'disputed' => [SessionStatus::Disputed],
        ];
    }

    public function test_cancelling_twice_refunds_once(): void
    {
        $this->cancel($this->candidate)->assertOk();

        $this->assertProblem($this->cancel($this->candidate), 409, 'INVALID_SESSION_STATE');
        $this->assertProblem($this->cancel($this->recruiter()), 409, 'INVALID_SESSION_STATE');

        $this->assertCount(1, $this->fakeGateway()->refunds);
        $this->assertSame('candidate', Session::findOrFail($this->sessionId)->cancelled_by);
    }

    public function test_a_reason_is_required(): void
    {
        foreach (['', 'ab', str_repeat('a', 501)] as $reason) {
            $response = $this->cancel($this->candidate, $reason);

            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame(['reason'], array_keys($response->json('errors')));
        }
        $this->assertSame(SessionStatus::Confirmed, Session::findOrFail($this->sessionId)->status);
    }

    public function test_only_the_two_participants_can_cancel_or_ask_for_a_quote(): void
    {
        $stranger = $this->register();
        $otherRecruiter = $this->recruiter('Mona Adel');

        foreach ([$stranger, $otherRecruiter] as $who) {
            $this->assertProblem($this->quote($who), 404, 'NOT_FOUND');
            $this->assertProblem($this->cancel($who), 404, 'NOT_FOUND');
        }
        $this->assertProblem($this->api('POST', "/sessions/{$this->sessionId}/cancel", ['reason' => 'Guest']), 401, 'TOKEN_INVALID');
        $this->assertSame(SessionStatus::Confirmed, Session::findOrFail($this->sessionId)->status);
    }

    public function test_cancelling_tells_the_apps(): void
    {
        Event::fake([SessionStatusChanged::class]);

        $this->cancel($this->candidate)->assertOk();

        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $e) => $e->sessionId === $this->sessionId && $e->status === SessionStatus::Cancelled);
    }
}
