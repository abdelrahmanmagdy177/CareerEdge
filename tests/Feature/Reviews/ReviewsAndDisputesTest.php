<?php

namespace Tests\Feature\Reviews;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Models\RecruiterProfile;
use App\Modules\Reviews\Models\Review;
use App\Modules\Scheduling\Broadcasts\SessionStatusChanged;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\Support\RunsSessions;
use Tests\TestCase;

/**
 * The session under test is played on 2026-11-16 and ends at 16:40.
 */
class ReviewsAndDisputesTest extends TestCase
{
    use BooksSessions, CreatesRecruiters, RefreshDatabase, RunsSessions;

    private const COMPLAINT = 'The recruiter left after ten minutes and did not come back.';

    /** @var array<string, mixed> */
    private array $candidate;

    /** @var array{id: string, recruiter: array<string, mixed>, endedAt: string} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->candidate = $this->register(['fullName' => 'Ahmed Hassan']);
        $this->session = $this->completedSession($this->candidate);
    }

    /** @param  array<string, mixed>  $body */
    private function review(array $body = ['rating' => 5], ?array $who = null, ?string $sessionId = null): TestResponse
    {
        return $this->api('POST', '/sessions/'.($sessionId ?? $this->session['id']).'/review', $body, $this->tokenOf($who ?? $this->candidate));
    }

    private function dispute(string $reason = self::COMPLAINT, ?array $who = null): TestResponse
    {
        return $this->api('POST', "/sessions/{$this->session['id']}/dispute", ['reason' => $reason], $this->tokenOf($who ?? $this->candidate));
    }

    private function recruiterId(): string
    {
        return $this->session['recruiter']['user']['id'];
    }

    // ───────────── Reviews ─────────────

    public function test_a_candidate_reviews_a_completed_session(): void
    {
        $response = $this->review(['rating' => 5, 'comment' => 'Very practical feedback.'])->assertCreated();

        $response->assertExactJson([
            'id' => $response->json('id'),
            'sessionId' => $this->session['id'],
            'rating' => 5,
            'comment' => 'Very practical feedback.',
            // Reviews are public, so only the first name is shown.
            'candidateFirstName' => 'Ahmed',
            'createdAt' => '2026-11-16T16:40:00Z',
        ]);

        $this->api('GET', "/sessions/{$this->session['id']}", token: $this->tokenOf($this->candidate))->assertJson(['hasReview' => true]);
    }

    public function test_a_session_shows_no_review_until_there_is_one(): void
    {
        $this->api('GET', "/sessions/{$this->session['id']}", token: $this->tokenOf($this->candidate))->assertJson(['hasReview' => false]);
    }

    public function test_the_comment_is_optional(): void
    {
        $this->review(['rating' => 4])->assertCreated()->assertJson(['rating' => 4, 'comment' => null]);
    }

    public function test_reviews_update_the_recruiters_rating_everywhere_it_is_shown(): void
    {
        $this->review(['rating' => 5])->assertCreated();

        // A second candidate has a session with the same recruiter and gives 4.
        $this->travelTo('2026-11-17 10:00:00');
        $omar = $this->register(['fullName' => 'Omar Nabil']);
        $recruiter = $this->session['recruiter'];
        $offeringId = $this->api('GET', '/me/offerings', token: $this->tokenOf($recruiter))->json('0.id');
        $slot = $this->slot($this->tokenOf($recruiter), '+1 day 16:00', '+1 day 17:00');
        $second = $this->bookAndPay($omar['accessToken'], ['offeringId' => $offeringId, 'slotId' => $slot['id']]);
        $this->travelTo($second['scheduledStart']);
        $this->api('POST', "/sessions/{$second['id']}/join", token: $this->tokenOf($recruiter))->assertOk();
        $this->api('POST', "/sessions/{$second['id']}/end", token: $this->tokenOf($recruiter))->assertOk();
        $this->review(['rating' => 4], $omar, $second['id'])->assertCreated();

        // (5 + 4) / 2 = 4.5, from two reviews.
        $this->api('GET', '/me/recruiter-profile', token: $this->tokenOf($recruiter))->assertJson(['ratingAvg' => 4.5, 'ratingCount' => 2]);
        $this->api('GET', "/recruiters/{$this->recruiterId()}", token: $this->tokenOf($omar))->assertJson(['ratingAvg' => 4.5, 'ratingCount' => 2]);
        $found = $this->api('GET', '/recruiters?minRating=4.5', token: $this->tokenOf($omar))->json('items');
        $this->assertSame([$this->recruiterId()], array_column($found, 'userId'));
        $this->assertSame([], $this->api('GET', '/recruiters?minRating=4.6', token: $this->tokenOf($omar))->json('items'));
    }

    public function test_an_average_with_many_decimals_is_stored_to_two_places(): void
    {
        $this->review(['rating' => 5])->assertCreated();
        // Two more reviews written directly: 5, 4, 4 averages 4.3333...
        foreach ([4, 4] as $rating) {
            $other = $this->completedSession($this->register(), 'Another Recruiter '.uniqid(), '+1 day');
            Review::create([
                'session_id' => $other['id'], 'candidate_id' => $this->candidate['user']['id'],
                'recruiter_id' => $this->recruiterId(), 'rating' => $rating,
            ]);
        }
        // Trigger the recalculation with one more real review of a new session.
        $again = $this->completedSession($this->candidate, 'Sara Second '.uniqid(), '+1 day');
        Session::whereKey($again['id'])->update(['recruiter_id' => $this->recruiterId()]);
        $this->review(['rating' => 4], sessionId: $again['id'])->assertCreated();

        // 5, 4, 4, 4 → 4.25
        $this->assertSame('4.25', RecruiterProfile::findOrFail($this->recruiterId())->getRawOriginal('rating_avg'));
    }

    public function test_a_session_can_be_reviewed_only_once(): void
    {
        $this->review(['rating' => 5])->assertCreated();

        $this->assertProblem($this->review(['rating' => 1]), 409, 'ALREADY_REVIEWED');

        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame(5, Review::firstOrFail()->rating);
        $this->api('GET', '/me/recruiter-profile', token: $this->tokenOf($this->session['recruiter']))->assertJson(['ratingAvg' => 5, 'ratingCount' => 1]);
    }

    public function test_the_database_itself_refuses_a_second_review_or_a_rating_out_of_range(): void
    {
        $this->review(['rating' => 5])->assertCreated();
        $row = fn (string $sessionId, int $rating) => [
            'session_id' => $sessionId, 'candidate_id' => $this->candidate['user']['id'],
            'recruiter_id' => $this->recruiterId(), 'rating' => $rating,
        ];

        foreach ([$row($this->session['id'], 4), $row($this->completedSession($this->candidate, 'Mona Adel', '+1 day')['id'], 6)] as $attributes) {
            try {
                DB::transaction(fn () => Review::create($attributes));
                $this->fail('The invalid review was saved.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_only_a_completed_session_can_be_reviewed(SessionStatus $status): void
    {
        Session::whereKey($this->session['id'])->update(['status' => $status]);

        $this->assertProblem($this->review(), 409, 'INVALID_SESSION_STATE');
        $this->assertDatabaseCount('reviews', 0);
    }

    /** @return array<string, array{SessionStatus}> */
    public static function unfinishedStatuses(): array
    {
        return [
            'confirmed' => [SessionStatus::Confirmed],
            'in progress' => [SessionStatus::InProgress],
            'cancelled' => [SessionStatus::Cancelled],
            'no show' => [SessionStatus::NoShow],
            'disputed' => [SessionStatus::Disputed],
        ];
    }

    public function test_a_session_can_be_reviewed_for_14_days_after_it_ended(): void
    {
        // Ended 2026-11-16 16:40. One second before the 14 days are up: still fine.
        $this->travelTo('2026-11-30 16:39:59');
        $this->review()->assertCreated();
    }

    public function test_after_14_days_the_review_window_is_closed(): void
    {
        $this->travelTo('2026-11-30 16:40:01');

        $this->assertProblem($this->review(), 409, 'REVIEW_WINDOW_CLOSED');
        $this->assertDatabaseCount('reviews', 0);
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('invalidReviews')]
    public function test_invalid_reviews_are_rejected(array $body, string $field): void
    {
        $response = $this->review($body);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
        $this->assertDatabaseCount('reviews', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidReviews(): array
    {
        return [
            'no rating' => [['comment' => 'Nice'], 'rating'],
            'zero' => [['rating' => 0], 'rating'],
            'six' => [['rating' => 6], 'rating'],
            'half star' => [['rating' => 4.5], 'rating'],
            'rating as text' => [['rating' => '5'], 'rating'],
            'comment over 1000 characters' => [['rating' => 5, 'comment' => str_repeat('a', 1001)], 'comment'],
        ];
    }

    public function test_only_the_candidate_of_the_session_can_review_it(): void
    {
        $this->assertProblem($this->review(who: $this->register()), 404, 'NOT_FOUND');
        $this->assertProblem($this->review(who: $this->session['recruiter']), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('POST', "/sessions/{$this->session['id']}/review", ['rating' => 5]), 401, 'TOKEN_INVALID');
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_recruiters_reviews_are_listed_newest_first_with_paging(): void
    {
        $this->review(['rating' => 5, 'comment' => 'First'])->assertCreated();
        $this->travelTo('2026-11-17 10:00:00');
        $second = $this->completedSession($this->candidate, 'Temp Recruiter', '+1 day');
        Session::whereKey($second['id'])->update(['recruiter_id' => $this->recruiterId()]);
        $this->review(['rating' => 3, 'comment' => 'Second'], sessionId: $second['id'])->assertCreated();
        $reader = $this->tokenOf($this->register());
        $uri = "/recruiters/{$this->recruiterId()}/reviews";

        $all = $this->api('GET', $uri, token: $reader)->assertOk()->json();
        $this->assertSame(['items', 'page', 'pageSize', 'totalCount'], array_keys($all));
        $this->assertSame(['Second', 'First'], array_column($all['items'], 'comment'));
        $this->assertSame([1, 20, 2], [$all['page'], $all['pageSize'], $all['totalCount']]);
        $this->assertSame(['id', 'sessionId', 'rating', 'comment', 'candidateFirstName', 'createdAt'], array_keys($all['items'][0]));

        $page2 = $this->api('GET', "$uri?pageSize=1&page=2", token: $reader)->json();
        $this->assertSame(['First'], array_column($page2['items'], 'comment'));
        $this->assertSame(2, $page2['totalCount']);
    }

    public function test_reviews_of_a_recruiter_who_is_not_approved_are_not_public(): void
    {
        $this->review()->assertCreated();
        RecruiterProfile::whereKey($this->recruiterId())->update(['verification_status' => VerificationStatus::Rejected]);
        $reader = $this->tokenOf($this->register());

        $this->assertProblem($this->api('GET', "/recruiters/{$this->recruiterId()}/reviews", token: $reader), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', '/recruiters/01a1c737-b065-7130-8d08-f913763e916c/reviews', token: $reader), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', "/recruiters/{$this->recruiterId()}/reviews"), 401, 'TOKEN_INVALID');
    }

    // ───────────── Disputes ─────────────

    public function test_a_candidate_opens_a_dispute_on_a_completed_session(): void
    {
        Event::fake([SessionStatusChanged::class]);

        $response = $this->dispute()->assertCreated();

        $response->assertExactJson([
            'id' => $response->json('id'),
            'sessionId' => $this->session['id'],
            'reason' => self::COMPLAINT,
            'status' => 'open',
            'resolutionNote' => null,
            'createdAt' => '2026-11-16T16:40:00Z',
            'resolvedAt' => null,
        ]);
        $this->assertSame(SessionStatus::Disputed, Session::findOrFail($this->session['id'])->status);
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $e) => $e->status === SessionStatus::Disputed);
    }

    public function test_a_session_can_be_disputed_only_once(): void
    {
        $this->dispute()->assertCreated();

        $this->assertProblem($this->dispute('A second complaint about the very same session.'), 409, 'INVALID_SESSION_STATE');
        $this->assertDatabaseCount('disputes', 1);
    }

    public function test_a_dispute_can_be_opened_for_24_hours_after_the_session_ended(): void
    {
        $this->travelTo('2026-11-17 16:39:59');

        $this->dispute()->assertCreated();
    }

    public function test_after_24_hours_the_dispute_window_is_closed(): void
    {
        $this->travelTo('2026-11-17 16:40:01');

        $this->assertProblem($this->dispute(), 409, 'DISPUTE_WINDOW_CLOSED');
        $this->assertDatabaseCount('disputes', 0);
        $this->assertSame(SessionStatus::Completed, Session::findOrFail($this->session['id'])->status);
    }

    #[DataProvider('undisputableStatuses')]
    public function test_only_a_completed_session_can_be_disputed(SessionStatus $status): void
    {
        Session::whereKey($this->session['id'])->update(['status' => $status]);

        $this->assertProblem($this->dispute(), 409, 'INVALID_SESSION_STATE');
        $this->assertDatabaseCount('disputes', 0);
    }

    /** @return array<string, array{SessionStatus}> */
    public static function undisputableStatuses(): array
    {
        return [
            'confirmed' => [SessionStatus::Confirmed],
            'in progress' => [SessionStatus::InProgress],
            'cancelled' => [SessionStatus::Cancelled],
            'no show' => [SessionStatus::NoShow],
        ];
    }

    public function test_the_reason_must_say_something(): void
    {
        foreach (['', 'Too short.', str_repeat('a', 2001)] as $reason) {
            $response = $this->dispute($reason);

            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame(['reason'], array_keys($response->json('errors')));
        }
        $this->assertDatabaseCount('disputes', 0);
    }

    public function test_only_the_candidate_of_the_session_can_dispute_it(): void
    {
        $this->assertProblem($this->dispute(who: $this->register()), 404, 'NOT_FOUND');
        $this->assertProblem($this->dispute(who: $this->session['recruiter']), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('POST', "/sessions/{$this->session['id']}/dispute", ['reason' => self::COMPLAINT]), 401, 'TOKEN_INVALID');
        $this->assertSame(SessionStatus::Completed, Session::findOrFail($this->session['id'])->status);
    }

    public function test_a_review_given_before_the_dispute_stays_but_none_can_be_added_after(): void
    {
        $this->review(['rating' => 2])->assertCreated();
        $this->dispute()->assertCreated();

        $this->assertDatabaseCount('reviews', 1);
        $this->api('GET', "/sessions/{$this->session['id']}", token: $this->tokenOf($this->candidate))
            ->assertJson(['status' => 'disputed', 'hasReview' => true]);
    }
}
