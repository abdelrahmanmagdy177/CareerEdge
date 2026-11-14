<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Identity\Enums\UserRole;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

class SessionsListTest extends TestCase
{
    use BooksSessions, CreatesRecruiters, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $ahmed;

    /** @var array<string, mixed> */
    private array $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->ahmed = $this->register(['fullName' => 'Ahmed Hassan']);
        $this->sara = $this->recruiter('Sara Mostafa');
        $this->offer($this->sara['accessToken']);
    }

    /** A slot of Sara's, booked by the given candidate. Returns the session. */
    private function bookWithSara(string $candidateToken, string $start, string $end, bool $pay = true): array
    {
        $offeringId = $this->api('GET', '/me/offerings', token: $this->sara['accessToken'])->json('0.id');
        $slot = $this->slot($this->sara['accessToken'], $start, $end);
        $body = ['offeringId' => $offeringId, 'slotId' => $slot['id']];

        return $pay
            ? $this->bookAndPay($candidateToken, $body)
            : $this->book($candidateToken, $body)->assertCreated()->json();
    }

    /** @return list<string> session ids */
    private function ids(string $token, string $query = ''): array
    {
        return array_column($this->api('GET', "/sessions$query", token: $token)->assertOk()->json('items'), 'id');
    }

    private function loginAs(array $auth): string
    {
        return $this->api('POST', '/auth/login', ['email' => $auth['user']['email'], 'password' => self::PASSWORD])->json('accessToken');
    }

    public function test_a_candidate_sees_only_their_own_sessions(): void
    {
        $omar = $this->register(['fullName' => 'Omar Nabil'])['accessToken'];
        $mine = $this->bookWithSara($this->ahmed['accessToken'], '+1 day 16:00', '+1 day 17:00');
        $his = $this->bookWithSara($omar, '+2 days 16:00', '+2 days 17:00');

        $this->assertSame([$mine['id']], $this->ids($this->ahmed['accessToken']));
        $this->assertSame([$his['id']], $this->ids($omar));

        $this->api('GET', "/sessions/{$mine['id']}", token: $this->ahmed['accessToken'])->assertOk();
        $this->assertProblem($this->api('GET', "/sessions/{$mine['id']}", token: $omar), 404, 'NOT_FOUND');
    }

    public function test_the_list_has_the_paging_shape_and_full_session_objects(): void
    {
        $session = $this->bookWithSara($this->ahmed['accessToken'], '+1 day 16:00', '+1 day 17:00');

        $list = $this->api('GET', '/sessions', token: $this->ahmed['accessToken'])->assertOk()->json();

        $this->assertSame(['items', 'page', 'pageSize', 'totalCount'], array_keys($list));
        $this->assertSame([1, 20, 1], [$list['page'], $list['pageSize'], $list['totalCount']]);
        // The same object the details endpoint returns.
        $this->assertSame($session, $list['items'][0]);
    }

    public function test_a_recruiter_sees_a_session_only_after_it_is_paid(): void
    {
        $recruiter = $this->sara['accessToken'];
        $unpaid = $this->bookWithSara($this->ahmed['accessToken'], '+1 day 16:00', '+1 day 17:00', pay: false);

        $this->assertSame([], $this->ids($recruiter));
        $this->assertProblem($this->api('GET', "/sessions/{$unpaid['id']}", token: $recruiter), 404, 'NOT_FOUND');

        $paymentId = $this->checkout($this->ahmed['accessToken'], $unpaid['id'])->json('paymentId');
        $this->webhook($paymentId)->assertOk();

        $this->assertSame([$unpaid['id']], $this->ids($recruiter));
        $this->api('GET', "/sessions/{$unpaid['id']}", token: $recruiter)
            ->assertOk()
            ->assertJson(['status' => 'confirmed', 'candidate' => ['fullName' => 'Ahmed Hassan']]);
    }

    public function test_a_recruiter_does_not_see_other_recruiters_sessions(): void
    {
        $mona = $this->recruiter('Mona Adel');
        $session = $this->bookWithSara($this->ahmed['accessToken'], '+1 day 16:00', '+1 day 17:00');

        $this->assertSame([], $this->ids($mona['accessToken']));
        $this->assertProblem($this->api('GET', "/sessions/{$session['id']}", token: $mona['accessToken']), 404, 'NOT_FOUND');
    }

    public function test_an_admin_sees_every_session(): void
    {
        $session = $this->bookWithSara($this->ahmed['accessToken'], '+1 day 16:00', '+1 day 17:00', pay: false);
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
        $this->assertSame(UserRole::Admin, $admin->role);
        $token = $this->api('POST', '/auth/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD])->json('accessToken');

        $this->assertSame([$session['id']], $this->ids($token));
        $this->api('GET', "/sessions/{$session['id']}", token: $token)->assertOk();
    }

    public function test_upcoming_lists_the_next_session_first_and_past_the_most_recent_first(): void
    {
        $token = $this->ahmed['accessToken'];
        $later = $this->bookWithSara($token, '+5 days 10:00', '+5 days 11:00');
        $soon = $this->bookWithSara($token, '+1 day 16:00', '+1 day 17:00');
        $unpaid = $this->bookWithSara($token, '+3 days 10:00', '+3 days 11:00', pay: false);
        $cancelled = $this->bookWithSara($token, '+4 days 10:00', '+4 days 11:00');
        // What cancelling will do in milestone M6.
        Session::whereKey($cancelled['id'])->update(['status' => SessionStatus::Cancelled]);

        $this->assertSame([$soon['id'], $unpaid['id'], $later['id']], $this->ids($token, '?scope=upcoming'));
        // A cancelled session is over, whatever its date.
        $this->assertSame([$cancelled['id']], $this->ids($token, '?scope=past'));
        $this->assertSame([$later['id'], $cancelled['id'], $unpaid['id'], $soon['id']], $this->ids($token, '?scope=all'));
        $this->assertSame($this->ids($token, '?scope=all'), $this->ids($token));

        // Two days on, the first session is behind us.
        $this->travelTo('2026-11-17 10:00:00');
        $token = $this->loginAs($this->ahmed);
        $this->assertSame([$unpaid['id'], $later['id']], $this->ids($token, '?scope=upcoming'));
        $this->assertSame([$cancelled['id'], $soon['id']], $this->ids($token, '?scope=past'));
    }

    public function test_filter_by_one_or_several_statuses(): void
    {
        $token = $this->ahmed['accessToken'];
        $confirmed = $this->bookWithSara($token, '+1 day 16:00', '+1 day 17:00');
        $pending = $this->bookWithSara($token, '+2 days 10:00', '+2 days 11:00', pay: false);

        $this->assertSame([$confirmed['id']], $this->ids($token, '?status=confirmed'));
        $this->assertSame([$pending['id']], $this->ids($token, '?status=pending_payment'));
        $this->assertSame([$pending['id'], $confirmed['id']], $this->ids($token, '?status=confirmed,pending_payment'));
        $this->assertSame([], $this->ids($token, '?status=completed'));
    }

    public function test_invalid_list_parameters_are_rejected(): void
    {
        $token = $this->ahmed['accessToken'];

        foreach (['status=confirmed,flying' => 'status', 'scope=soon' => 'scope', 'page=0' => 'page', 'pageSize=101' => 'pageSize'] as $query => $field) {
            $response = $this->api('GET', "/sessions?$query", token: $token);

            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame([$field], array_keys($response->json('errors')));
        }
    }

    public function test_paging(): void
    {
        $token = $this->ahmed['accessToken'];
        foreach ([1, 2, 3] as $day) {
            $this->bookWithSara($token, "+$day days 10:00", "+$day days 11:00");
        }

        $first = $this->api('GET', '/sessions?scope=upcoming&pageSize=2', token: $token)->json();
        $second = $this->api('GET', '/sessions?scope=upcoming&pageSize=2&page=2', token: $token)->json();

        $this->assertSame([2, 1], [count($first['items']), count($second['items'])]);
        $this->assertSame([3, 3], [$first['totalCount'], $second['totalCount']]);
        $this->assertSame(['2026-11-16T10:00:00Z', '2026-11-17T10:00:00Z'], array_column($first['items'], 'scheduledStart'));
        $this->assertSame(['2026-11-18T10:00:00Z'], array_column($second['items'], 'scheduledStart'));
    }

    public function test_the_endpoints_need_a_token_and_a_real_id(): void
    {
        $this->assertProblem($this->api('GET', '/sessions'), 401, 'TOKEN_INVALID');
        $this->assertProblem($this->api('GET', '/sessions/01a1c737-b065-7130-8d08-f913763e916c', token: $this->ahmed['accessToken']), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', '/sessions/123', token: $this->ahmed['accessToken']), 404, 'NOT_FOUND');
    }
}
