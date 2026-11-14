<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Broadcasts\SessionEndingSoon;
use App\Modules\Scheduling\Broadcasts\SessionStatusChanged;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Events\SessionCompleted;
use App\Modules\Scheduling\Events\SessionNoShow;
use App\Modules\Scheduling\Models\Session;
use App\Modules\Scheduling\Voice\LiveKitVoiceProvider;
use App\Modules\Scheduling\Voice\VoiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

/**
 * The session in these tests is on 2026-11-16 from 16:00 to 16:45 (a voice mock interview).
 */
class LiveSessionTest extends TestCase
{
    use BooksSessions, CreatesRecruiters, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $candidate;

    /** @var array<string, mixed> */
    private array $recruiter;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->candidate = $this->register(['fullName' => 'Ahmed Hassan']);
        $setup = $this->bookable();
        $this->recruiter = $setup['recruiter'];
        $this->sessionId = $this->bookAndPay($this->candidate['accessToken'], $setup['body'])['id'];
    }

    /** Moves the clock and logs both people in again (access tokens live 15 minutes). */
    private function at(string $time): void
    {
        $this->travelTo("2026-11-16 $time");
        foreach (['candidate', 'recruiter'] as $who) {
            $this->{$who}['accessToken'] = $this->api('POST', '/auth/login', [
                'email' => $this->{$who}['user']['email'], 'password' => self::PASSWORD,
            ])->json('accessToken');
        }
    }

    private function join(string $who)
    {
        return $this->api('POST', "/sessions/{$this->sessionId}/join", token: $this->{$who}['accessToken']);
    }

    private function end(string $who = 'recruiter')
    {
        return $this->api('POST', "/sessions/{$this->sessionId}/end", token: $this->{$who}['accessToken']);
    }

    private function sessionStatus(): string
    {
        return Session::findOrFail($this->sessionId)->status->value;
    }

    public function test_the_first_join_starts_the_session(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $this->at('15:55:00');

        $response = $this->join('candidate')->assertOk();

        $this->assertSame(['session', 'voice'], array_keys($response->json()));
        $response->assertJsonPath('session.status', 'in_progress');
        $response->assertJsonPath('session.actualStart', '2026-11-16T15:55:00Z');
        $response->assertJsonPath('session.chatOpen', true);
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $e) => $e->sessionId === $this->sessionId
            && $e->broadcastWith() === ['sessionId' => $this->sessionId, 'status' => 'in_progress', 'at' => '2026-11-16T15:55:00Z']
            && $e->broadcastAs() === 'session.status-changed'
            && $e->broadcastOn()->name === "private-session.{$this->sessionId}");
    }

    public function test_the_second_person_joining_does_not_restart_it(): void
    {
        $this->at('15:55:00');
        $this->join('candidate')->assertOk();
        Event::fake([SessionStatusChanged::class]);

        $this->at('16:02:00');
        $this->join('recruiter')->assertOk()
            ->assertJsonPath('session.status', 'in_progress')
            // Still the moment the first person came in.
            ->assertJsonPath('session.actualStart', '2026-11-16T15:55:00Z');

        Event::assertNotDispatched(SessionStatusChanged::class);
        $this->assertDatabaseCount('session_attendances', 2);
    }

    public function test_joining_again_gives_a_fresh_pass_and_keeps_the_first_attendance_time(): void
    {
        $this->at('15:55:00');
        $this->join('candidate')->assertOk();

        $this->at('16:10:00');
        $this->join('candidate')->assertOk()->assertJsonPath('session.status', 'in_progress');

        $this->assertDatabaseCount('session_attendances', 1);
        $this->assertDatabaseHas('session_attendances', ['session_id' => $this->sessionId, 'first_joined_at' => '2026-11-16 15:55:00+00']);
    }

    public function test_a_voice_session_returns_a_call_pass_for_this_user_and_this_room(): void
    {
        $this->at('16:00:00');

        $voice = $this->join('candidate')->json('voice');

        $this->assertSame(['provider', 'serverUrl', 'roomName', 'token', 'expiresAt'], array_keys($voice));
        $this->assertSame("session-{$this->sessionId}", $voice['roomName']);
        // Valid until 10 minutes after the scheduled end.
        $this->assertSame('2026-11-16T16:55:00Z', $voice['expiresAt']);
        $this->assertStringContainsString($this->candidate['user']['id'], $voice['token']);
    }

    public function test_a_chat_session_has_no_call_pass(): void
    {
        $setup = $this->bookable('Mona Adel', ['mode' => 'chat'], '+2 days 10:00', '+2 days 11:00');
        $chatSession = $this->bookAndPay($this->candidate['accessToken'], $setup['body'])['id'];
        $this->travelTo('2026-11-17 10:00:00');
        $token = $this->api('POST', '/auth/login', ['email' => $this->candidate['user']['email'], 'password' => self::PASSWORD])->json('accessToken');

        $this->api('POST', "/sessions/$chatSession/join", token: $token)
            ->assertOk()
            ->assertJsonPath('voice', null)
            ->assertJsonPath('session.status', 'in_progress');
    }

    public function test_a_livekit_pass_is_a_signed_jwt_that_grants_exactly_one_room(): void
    {
        $this->app->bind(VoiceProvider::class, fn () => new LiveKitVoiceProvider('wss://voice.example.com', 'APIkey123', 'top-secret'));
        $this->at('16:00:00');

        $voice = $this->join('recruiter')->json('voice');

        $this->assertSame('wss://voice.example.com', $voice['serverUrl']);
        [$header, $claims, $signature] = explode('.', $voice['token']);
        $decode = fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $decode($header));
        $this->assertSame([
            'iss' => 'APIkey123',
            'sub' => $this->recruiter['user']['id'],
            'name' => 'Sara Mostafa',
            'nbf' => strtotime('2026-11-16 16:00:00 UTC'),
            'exp' => strtotime('2026-11-16 16:55:00 UTC'),
            'video' => ['room' => "session-{$this->sessionId}", 'roomJoin' => true, 'canPublish' => true, 'canSubscribe' => true],
        ], $decode($claims));

        // The signature is the HMAC of the first two parts with the API secret, so LiveKit
        // (which knows the secret) accepts it and nobody else can forge one.
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$claims", 'top-secret', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $signature);
        $this->assertStringNotContainsString('top-secret', $voice['token']);
    }

    #[DataProvider('outsideTheWindow')]
    public function test_joining_is_only_possible_from_10_minutes_before_the_start_until_the_end(string $time): void
    {
        $this->at($time);

        $this->assertProblem($this->join('candidate'), 409, 'SESSION_NOT_JOINABLE');
        $this->assertSame('confirmed', $this->sessionStatus());
        $this->assertDatabaseCount('session_attendances', 0);
    }

    /** @return array<string, array{string}> */
    public static function outsideTheWindow(): array
    {
        return [
            '11 minutes early' => ['15:49:00'],
            'one second too early' => ['15:49:59'],
            'exactly at the end' => ['16:45:00'],
            'after the end' => ['16:50:00'],
        ];
    }

    #[DataProvider('insideTheWindow')]
    public function test_the_edges_of_the_join_window_are_allowed(string $time): void
    {
        $this->at($time);

        $this->join('recruiter')->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function insideTheWindow(): array
    {
        return ['exactly 10 minutes early' => ['15:50:00'], 'one second before the end' => ['16:44:59']];
    }

    public function test_a_session_that_is_not_paid_or_already_over_cannot_be_joined(): void
    {
        $this->at('16:00:00');

        foreach ([SessionStatus::PendingPayment, SessionStatus::Completed, SessionStatus::Cancelled, SessionStatus::NoShow] as $status) {
            Session::whereKey($this->sessionId)->update(['status' => $status]);

            $this->assertProblem($this->join('candidate'), 409, 'INVALID_SESSION_STATE');
        }
    }

    public function test_only_the_two_participants_can_join(): void
    {
        $this->at('16:00:00');
        $stranger = $this->register()['accessToken'];
        $otherRecruiter = $this->recruiter('Mona Adel')['accessToken'];
        $uri = "/sessions/{$this->sessionId}/join";

        $this->assertProblem($this->api('POST', $uri, token: $stranger), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('POST', $uri, token: $otherRecruiter), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('POST', $uri), 401, 'TOKEN_INVALID');
        $this->assertSame('confirmed', $this->sessionStatus());
    }

    public function test_the_recruiter_ends_the_session(): void
    {
        $this->at('16:00:00');
        $this->join('recruiter')->assertOk();
        $this->join('candidate')->assertOk();
        Event::fake([SessionStatusChanged::class, SessionCompleted::class]);

        $this->at('16:40:00');
        $this->end()->assertOk()
            ->assertJson(['status' => 'completed', 'actualEnd' => '2026-11-16T16:40:00Z', 'chatOpen' => false]);

        Event::assertDispatched(SessionCompleted::class, fn (SessionCompleted $e) => $e->sessionId === $this->sessionId);
        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $e) => $e->status === SessionStatus::Completed);

        // Ending twice is refused, and tells nobody a second time.
        Event::fake([SessionCompleted::class]);
        $this->assertProblem($this->end(), 409, 'INVALID_SESSION_STATE');
        Event::assertNotDispatched(SessionCompleted::class);
    }

    public function test_a_session_that_has_not_started_cannot_be_ended(): void
    {
        $this->at('16:00:00');

        $this->assertProblem($this->end(), 409, 'INVALID_SESSION_STATE');
        $this->assertSame('confirmed', $this->sessionStatus());
    }

    public function test_only_the_sessions_own_recruiter_can_end_it(): void
    {
        $this->at('16:00:00');
        $this->join('candidate')->assertOk();
        $otherRecruiter = $this->recruiter('Mona Adel')['accessToken'];

        $this->assertProblem($this->end('candidate'), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('POST', "/sessions/{$this->sessionId}/end", token: $otherRecruiter), 404, 'NOT_FOUND');
        $this->assertSame('in_progress', $this->sessionStatus());
    }

    public function test_a_session_nobody_ended_is_completed_10_minutes_after_its_end_if_the_recruiter_came(): void
    {
        $this->at('16:00:00');
        $this->join('recruiter')->assertOk();
        Event::fake([SessionCompleted::class, SessionNoShow::class]);

        // 9 minutes 59 seconds after the end: not yet.
        $this->travelTo('2026-11-16 16:54:59');
        $this->artisan('sessions:close-finished')->assertSuccessful();
        $this->assertSame('in_progress', $this->sessionStatus());

        $this->travelTo('2026-11-16 16:55:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();

        $session = Session::findOrFail($this->sessionId);
        $this->assertSame(SessionStatus::Completed, $session->status);
        // Nobody pressed "end", so the scheduled end stands.
        $this->assertSame('2026-11-16 16:45:00', $session->actual_end->format('Y-m-d H:i:s'));
        Event::assertDispatched(SessionCompleted::class);
        Event::assertNotDispatched(SessionNoShow::class);
    }

    /**
     * Business rule 7. The recruiter joining alone is covered by the test above: completed.
     *
     * @param  list<string>  $whoJoins
     */
    #[DataProvider('recruiterAbsent')]
    public function test_a_session_the_recruiter_never_joined_becomes_a_no_show(array $whoJoins): void
    {
        $this->at('16:00:00');
        foreach ($whoJoins as $who) {
            $this->join($who)->assertOk();
        }
        Event::fake([SessionCompleted::class, SessionNoShow::class]);

        $this->travelTo('2026-11-16 16:55:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();

        $session = Session::findOrFail($this->sessionId);
        $this->assertSame(SessionStatus::NoShow, $session->status);
        $this->assertNull($session->actual_end);
        Event::assertDispatched(SessionNoShow::class, fn (SessionNoShow $e) => $e->sessionId === $this->sessionId);
        Event::assertNotDispatched(SessionCompleted::class);
    }

    /** @return array<string, array{list<string>}> */
    public static function recruiterAbsent(): array
    {
        return ['only the candidate came' => [['candidate']], 'nobody came' => [[]]];
    }

    public function test_the_closing_job_leaves_other_sessions_alone_and_can_run_twice(): void
    {
        $this->at('16:00:00');
        $this->join('recruiter')->assertOk();
        $this->end()->assertOk();
        $endedAt = Session::findOrFail($this->sessionId)->actual_end;
        Event::fake([SessionCompleted::class, SessionNoShow::class]);

        $this->travelTo('2026-11-16 18:00:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();
        $this->artisan('sessions:close-finished')->assertSuccessful();

        // Already completed by the recruiter: untouched, and announced no second time.
        $this->assertEquals($endedAt, Session::findOrFail($this->sessionId)->actual_end);
        Event::assertNotDispatched(SessionCompleted::class);
    }

    public function test_five_minutes_before_the_end_the_session_is_told_once(): void
    {
        $this->at('16:00:00');
        $this->join('candidate')->assertOk();
        Event::fake([SessionEndingSoon::class]);

        // 5 minutes and 1 second left: too early.
        $this->travelTo('2026-11-16 16:39:59');
        $this->artisan('sessions:close-finished')->assertSuccessful();
        Event::assertNotDispatched(SessionEndingSoon::class);

        $this->travelTo('2026-11-16 16:40:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();
        $this->travelTo('2026-11-16 16:41:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();

        Event::assertDispatchedTimes(SessionEndingSoon::class, 1);
        Event::assertDispatched(SessionEndingSoon::class, fn (SessionEndingSoon $e) => $e->broadcastAs() === 'session.ending-soon'
            && $e->broadcastWith() === ['sessionId' => $this->sessionId, 'endsAt' => '2026-11-16T16:45:00Z']);
    }

    public function test_a_session_that_never_started_gets_no_ending_soon_event(): void
    {
        Event::fake([SessionEndingSoon::class]);

        $this->travelTo('2026-11-16 16:42:00');
        $this->artisan('sessions:close-finished')->assertSuccessful();

        Event::assertNotDispatched(SessionEndingSoon::class);
    }

    public function test_paying_tells_the_apps_the_session_is_confirmed(): void
    {
        Event::fake([SessionStatusChanged::class]);
        $setup = $this->bookable('Mona Adel', start: '+3 days 10:00', end: '+3 days 11:00');

        $session = $this->bookAndPay($this->candidate['accessToken'], $setup['body']);

        Event::assertDispatched(SessionStatusChanged::class, fn (SessionStatusChanged $e) => $e->sessionId === $session['id'] && $e->status === SessionStatus::Confirmed);
    }

    public function test_a_broken_websocket_server_does_not_break_the_request(): void
    {
        // Point the live events at a server that is not there.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1, 'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => 'a']);
        $this->at('16:00:00');

        // The join still works. The failed event is only logged.
        $this->join('candidate')->assertOk()->assertJsonPath('session.status', 'in_progress');
    }
}
