<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\Support\RunsSessions;
use Tests\TestCase;

/**
 * What happens to the held money after the session: payout, refund, or freeze.
 * completedSession() plays a session on 2026-11-16, 16:00 to 16:40.
 */
class SettlementTest extends TestCase
{
    use BooksSessions, CreatesRecruiters, RefreshDatabase, RunsSessions;

    /** @var array<string, mixed> */
    private array $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->candidate = $this->register(['fullName' => 'Ahmed Hassan']);
    }

    public function test_completing_a_session_schedules_the_payout_for_24_hours_later(): void
    {
        $session = $this->completedSession($this->candidate);

        $payment = $this->paymentOf($session['id']);
        $this->assertSame(PaymentStatus::Held, $payment->status);
        $this->assertSame('2026-11-17 16:40:00', $payment->release_scheduled_at->format('Y-m-d H:i:s'));

        $this->api('GET', "/sessions/{$session['id']}/payment", token: $this->tokenOf($this->candidate))
            ->assertJson(['status' => 'held', 'releaseScheduledAt' => '2026-11-17T16:40:00Z', 'releasedAt' => null]);
    }

    public function test_the_recruiter_is_paid_once_the_24_hours_are_over_and_not_a_second_before(): void
    {
        $session = $this->completedSession($this->candidate);

        $this->travelTo('2026-11-17 16:39:59');
        $this->artisan('payments:release-due')->assertSuccessful();
        $this->assertSame(PaymentStatus::Held, $this->paymentOf($session['id'])->status);

        $this->travelTo('2026-11-17 16:40:00');
        $this->artisan('payments:release-due')->assertSuccessful();

        $payment = $this->paymentOf($session['id']);
        $this->assertSame(PaymentStatus::Released, $payment->status);
        $this->assertSame('2026-11-17 16:40:00', $payment->released_at->format('Y-m-d H:i:s'));
        $this->assertNull($payment->release_scheduled_at);
        $this->assertSame(0, $payment->refunded_minor);
        $this->assertSame([], $this->fakeGateway()->refunds);

        // Running the job again changes nothing.
        $this->travel(1)->hours();
        $this->artisan('payments:release-due')->assertSuccessful();
        $this->assertSame('2026-11-17 16:40:00', $this->paymentOf($session['id'])->released_at->format('Y-m-d H:i:s'));

        $this->api('GET', "/sessions/{$session['id']}", token: $this->tokenOf($this->candidate))
            ->assertJson(['status' => 'completed', 'paymentStatus' => 'released']);
    }

    public function test_a_session_closed_by_the_job_also_gets_a_payout_date(): void
    {
        $setup = $this->bookable();
        $session = $this->bookAndPay($this->candidate['accessToken'], $setup['body']);
        $this->travelTo('2026-11-16 16:00:00');
        $this->api('POST', "/sessions/{$session['id']}/join", token: $this->tokenOf($setup['recruiter']))->assertOk();

        // Nobody ends it. The job closes it 10 minutes after its scheduled end (16:45).
        $this->travelTo('2026-11-16 16:55:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();

        $payment = $this->paymentOf($session['id']);
        $this->assertSame(PaymentStatus::Held, $payment->status);
        $this->assertSame('2026-11-17 16:55:00', $payment->release_scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_a_no_show_refunds_the_candidate_in_full(): void
    {
        $setup = $this->bookable();
        $session = $this->bookAndPay($this->candidate['accessToken'], $setup['body']);
        $this->travelTo('2026-11-16 16:00:00');
        // The candidate comes. The recruiter never does.
        $this->api('POST', "/sessions/{$session['id']}/join", token: $this->tokenOf($this->candidate))->assertOk();

        $this->travelTo('2026-11-16 16:55:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();

        $payment = $this->paymentOf($session['id']);
        $this->assertSame(SessionStatus::NoShow, Session::findOrFail($session['id'])->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(45000, $payment->refunded_minor);
        $this->assertNull($payment->released_at);
        $this->assertSame([['reference' => $payment->gateway_reference, 'amountMinor' => 45000]], $this->fakeGateway()->refunds);

        // Nothing is left to pay out later.
        $this->travel(2)->days();
        $this->artisan('payments:release-due')->assertSuccessful();
        $this->assertSame(PaymentStatus::Refunded, $this->paymentOf($session['id'])->status);
    }

    public function test_a_dispute_stops_the_payout_until_an_admin_decides(): void
    {
        $session = $this->completedSession($this->candidate);

        $this->api('POST', "/sessions/{$session['id']}/dispute", [
            'reason' => 'The recruiter left after ten minutes and did not come back.',
        ], $this->tokenOf($this->candidate))->assertCreated();

        $this->assertNull($this->paymentOf($session['id'])->release_scheduled_at);

        // Well past the 24 hours: still held.
        $this->travel(3)->days();
        $this->artisan('payments:release-due')->assertSuccessful();

        $payment = $this->paymentOf($session['id']);
        $this->assertSame(PaymentStatus::Held, $payment->status);
        $this->assertNull($payment->released_at);
    }

    public function test_each_session_is_settled_on_its_own(): void
    {
        $paidOut = $this->completedSession($this->candidate, 'Sara Mostafa', '+1 day');
        $this->travelTo('2026-11-17 10:00:00');
        $disputed = $this->completedSession($this->candidate, 'Mona Adel', '+1 day');
        $this->api('POST', "/sessions/{$disputed['id']}/dispute", [
            'reason' => 'The call quality made the session unusable.',
        ], $this->tokenOf($this->candidate))->assertCreated();

        $this->travelTo('2026-11-20 10:00:00');
        $this->artisan('payments:release-due')->assertSuccessful();

        $this->assertSame(PaymentStatus::Released, $this->paymentOf($paidOut['id'])->status);
        $this->assertSame(PaymentStatus::Held, $this->paymentOf($disputed['id'])->status);
    }
}
