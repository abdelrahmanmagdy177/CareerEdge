<?php

namespace Tests\Feature\Admin;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Reviews\Models\Dispute;
use App\Modules\Scheduling\Broadcasts\SessionStatusChanged;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActsAsAdmin;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\Support\RunsSessions;
use Tests\TestCase;

/**
 * The disputed session was played on 2026-11-16 (ended 16:40) and disputed right away.
 */
class AdminDisputesTest extends TestCase
{
    use ActsAsAdmin, BooksSessions, CreatesRecruiters, RefreshDatabase, RunsSessions;

    private const COMPLAINT = 'The recruiter left after ten minutes and did not come back.';

    /** @var array<string, mixed> */
    private array $candidate;

    /** @var array{id: string, recruiter: array<string, mixed>, endedAt: string} */
    private array $session;

    private string $disputeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->candidate = $this->register(['fullName' => 'Ahmed Hassan']);
        $this->session = $this->completedSession($this->candidate);
        $this->disputeId = $this->openDispute($this->session['id']);
    }

    private function openDispute(string $sessionId): string
    {
        return $this->api('POST', "/sessions/$sessionId/dispute", ['reason' => self::COMPLAINT], $this->tokenOf($this->candidate))
            ->assertCreated()
            ->json('id');
    }

    /** @param  array<string, mixed>  $body */
    private function resolve(array $body, ?string $token = null, ?string $disputeId = null): TestResponse
    {
        return $this->api('POST', '/admin/disputes/'.($disputeId ?? $this->disputeId).'/resolve', $body, $token ?? $this->adminToken());
    }

    public function test_the_admin_sees_the_disputes_oldest_first_and_can_filter_them(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $second = $this->completedSession($this->candidate, 'Mona Adel', '+1 day');
        $secondDispute = $this->openDispute($second['id']);
        $this->resolve(['resolution' => 'release', 'note' => 'The recording shows a full session.'])->assertOk();
        $admin = $this->adminToken();

        $all = $this->api('GET', '/admin/disputes', token: $admin)->assertOk()->json();
        $this->assertSame(['items', 'page', 'pageSize', 'totalCount'], array_keys($all));
        $this->assertSame([$this->disputeId, $secondDispute], array_column($all['items'], 'id'));
        $this->assertSame(2, $all['totalCount']);
        $this->assertSame(['id', 'sessionId', 'reason', 'status', 'resolutionNote', 'createdAt', 'resolvedAt'], array_keys($all['items'][0]));

        $ids = fn (string $status) => array_column($this->api('GET', "/admin/disputes?status=$status", token: $admin)->json('items'), 'id');
        $this->assertSame([$secondDispute], $ids('open'));
        $this->assertSame([$this->disputeId], $ids('resolved_release'));
        $this->assertSame([], $ids('resolved_refund'));
    }

    public function test_deciding_for_the_candidate_refunds_everything(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->travelTo('2026-11-18 09:00:00');

        $this->resolve(['resolution' => 'refund', 'note' => 'The chat log confirms the recruiter left early.'])->assertOk()->assertExactJson([
            'id' => $this->disputeId,
            'sessionId' => $this->session['id'],
            'reason' => self::COMPLAINT,
            'status' => 'resolved_refund',
            'resolutionNote' => 'The chat log confirms the recruiter left early.',
            'createdAt' => '2026-11-16T16:40:00Z',
            'resolvedAt' => '2026-11-18T09:00:00Z',
        ]);

        $this->assertSame(SessionStatus::Refunded, Session::findOrFail($this->session['id'])->status);
        $payment = $this->paymentOf($this->session['id']);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(45000, $payment->refunded_minor);
        $this->assertNull($payment->released_at);
        $this->assertSame([['reference' => $payment->gateway_reference, 'amountMinor' => 45000]], $this->fakeGateway()->refunds);
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $e) => $e->status === SessionStatus::Refunded);

        $this->api('GET', "/sessions/{$this->session['id']}", token: $this->tokenOf($this->candidate))
            ->assertJson(['status' => 'refunded', 'paymentStatus' => 'refunded']);
    }

    public function test_deciding_for_the_recruiter_pays_them_now(): void
    {
        $this->travelTo('2026-11-18 09:00:00');

        $this->resolve(['resolution' => 'release', 'note' => 'The session ran its full length.'])->assertOk()
            ->assertJson(['status' => 'resolved_release', 'resolvedAt' => '2026-11-18T09:00:00Z']);

        $this->assertSame(SessionStatus::Completed, Session::findOrFail($this->session['id'])->status);
        $payment = $this->paymentOf($this->session['id']);
        $this->assertSame(PaymentStatus::Released, $payment->status);
        $this->assertSame('2026-11-18 09:00:00', $payment->released_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $payment->refunded_minor);
        $this->assertSame([], $this->fakeGateway()->refunds);
    }

    public function test_a_dispute_is_decided_once(): void
    {
        $this->resolve(['resolution' => 'release', 'note' => 'The session ran its full length.'])->assertOk();

        // A second admin, or a second click, changes nothing and moves no money.
        $this->assertProblem($this->resolve(['resolution' => 'refund', 'note' => 'On second thought, refund.']), 409, 'DISPUTE_ALREADY_RESOLVED');

        $this->assertSame(Dispute::RESOLVED_RELEASE, Dispute::findOrFail($this->disputeId)->status);
        $this->assertSame(PaymentStatus::Released, $this->paymentOf($this->session['id'])->status);
        $this->assertSame([], $this->fakeGateway()->refunds);
    }

    public function test_after_the_recruiter_wins_the_candidate_can_still_review_but_not_dispute_again(): void
    {
        $this->resolve(['resolution' => 'release', 'note' => 'The session ran its full length.'])->assertOk();
        $token = $this->tokenOf($this->candidate);

        $this->api('POST', "/sessions/{$this->session['id']}/review", ['rating' => 2], $token)->assertCreated();
        $this->assertProblem(
            $this->api('POST', "/sessions/{$this->session['id']}/dispute", ['reason' => self::COMPLAINT], $token),
            409,
            'INVALID_SESSION_STATE',
        );
    }

    public function test_after_a_refund_the_session_cannot_be_reviewed(): void
    {
        $this->resolve(['resolution' => 'refund', 'note' => 'Refunding the candidate.'])->assertOk();

        $this->assertProblem(
            $this->api('POST', "/sessions/{$this->session['id']}/review", ['rating' => 1], $this->tokenOf($this->candidate)),
            409,
            'INVALID_SESSION_STATE',
        );
    }

    public function test_a_resolution_needs_a_valid_choice_and_a_note(): void
    {
        $cases = [
            [[], 'resolution'],
            [['resolution' => 'split', 'note' => 'Half each.'], 'resolution'],
            [['resolution' => 'refund'], 'note'],
            [['resolution' => 'refund', 'note' => 'ok'], 'note'],
            [['resolution' => 'refund', 'note' => str_repeat('a', 2001)], 'note'],
        ];

        foreach ($cases as [$body, $field]) {
            $response = $this->resolve($body);
            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertContains($field, array_keys($response->json('errors')));
        }

        $this->assertSame(Dispute::OPEN, Dispute::findOrFail($this->disputeId)->status);
        $this->assertSame(SessionStatus::Disputed, Session::findOrFail($this->session['id'])->status);
    }

    public function test_only_admins_can_see_or_resolve_disputes(): void
    {
        $body = ['resolution' => 'refund', 'note' => 'I want my money back.'];

        // The candidate cannot refund themselves, and the recruiter cannot pay themselves.
        foreach ([$this->candidate, $this->session['recruiter']] as $who) {
            $token = $this->tokenOf($who);
            $this->assertProblem($this->api('GET', '/admin/disputes', token: $token), 403, 'FORBIDDEN_ROLE');
            $this->assertProblem($this->resolve($body, $token), 403, 'FORBIDDEN_ROLE');
        }
        $this->assertProblem($this->api('GET', '/admin/disputes'), 401, 'TOKEN_INVALID');
        $this->assertSame(Dispute::OPEN, Dispute::findOrFail($this->disputeId)->status);
        $this->assertSame(PaymentStatus::Held, $this->paymentOf($this->session['id'])->status);
    }

    public function test_an_unknown_dispute_is_a_404_and_bad_list_parameters_are_rejected(): void
    {
        $body = ['resolution' => 'refund', 'note' => 'Refunding.'];
        $admin = $this->adminToken();

        $this->assertProblem($this->resolve($body, $admin, '01a1c737-b065-7130-8d08-f913763e916c'), 404, 'NOT_FOUND');
        $this->assertProblem($this->resolve($body, $admin, 'not-a-uuid'), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', '/admin/disputes?status=closed', token: $admin), 400, 'VALIDATION_FAILED');
    }

    public function test_the_payout_job_does_not_touch_a_dispute_before_or_after_it_is_decided(): void
    {
        // Days pass with the dispute open: nothing is paid.
        $this->travelTo('2026-11-20 10:00:00');
        $this->artisan('payments:release-due')->assertSuccessful();
        $this->assertSame(PaymentStatus::Held, $this->paymentOf($this->session['id'])->status);

        $this->resolve(['resolution' => 'refund', 'note' => 'Refunding the candidate.'])->assertOk();
        $this->artisan('payments:release-due')->assertSuccessful();

        // Refunded stays refunded: the job cannot pay out money that went back.
        $this->assertSame(PaymentStatus::Refunded, $this->paymentOf($this->session['id'])->status);
    }
}
