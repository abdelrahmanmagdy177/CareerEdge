<?php

namespace Tests\Feature\Messaging;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Broadcasts\MessageSent;
use App\Modules\Messaging\Models\Message;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

class ChatTest extends TestCase
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

    private function send(string $token, string $body, ?string $clientMessageId = null, ?string $sessionId = null): TestResponse
    {
        return $this->api('POST', '/sessions/'.($sessionId ?? $this->sessionId).'/messages', [
            'clientMessageId' => $clientMessageId ?? (string) Str::uuid(),
            'body' => $body,
        ], $token);
    }

    private function history(string $token, string $query = ''): array
    {
        return $this->api('GET', "/sessions/{$this->sessionId}/messages$query", token: $token)->assertOk()->json();
    }

    private function setStatus(SessionStatus $status): void
    {
        Session::whereKey($this->sessionId)->update(['status' => $status]);
    }

    public function test_a_participant_sends_a_message_and_the_other_side_gets_it_live(): void
    {
        Event::fake([MessageSent::class]);
        $clientId = (string) Str::uuid();

        $response = $this->send($this->recruiter['accessToken'], 'أهلا أحمد، جاهز نبدأ؟', $clientId)->assertCreated();

        $response->assertExactJson([
            'id' => $response->json('id'),
            'sessionId' => $this->sessionId,
            'senderId' => $this->recruiter['user']['id'],
            'clientMessageId' => $clientId,
            'body' => 'أهلا أحمد، جاهز نبدأ؟',
            'sentAt' => '2026-11-15T10:00:00Z',
        ]);

        // The live event carries exactly the object the REST call returned.
        Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->broadcastWith() === $response->json()
            && $e->broadcastAs() === 'message.sent'
            && $e->broadcastOn()->name === "private-session.{$this->sessionId}");
    }

    public function test_both_sides_read_the_same_history_newest_first(): void
    {
        $this->send($this->recruiter['accessToken'], 'Hello Ahmed')->assertCreated();
        $this->travel(1)->seconds();
        $this->send($this->candidate['accessToken'], 'Hello Sara')->assertCreated();
        $this->travel(1)->seconds();
        $this->send($this->recruiter['accessToken'], 'Shall we start?')->assertCreated();

        $asCandidate = $this->history($this->candidate['accessToken']);

        $this->assertSame(['items', 'hasMore'], array_keys($asCandidate));
        $this->assertSame(['Shall we start?', 'Hello Sara', 'Hello Ahmed'], array_column($asCandidate['items'], 'body'));
        $this->assertFalse($asCandidate['hasMore']);
        $this->assertSame($asCandidate, $this->history($this->recruiter['accessToken']));
    }

    public function test_older_messages_are_loaded_with_the_before_cursor(): void
    {
        foreach (range(1, 5) as $n) {
            $this->send($this->candidate['accessToken'], "Message $n")->assertCreated();
            $this->travel(1)->seconds();
        }
        $token = $this->candidate['accessToken'];

        $first = $this->history($token, '?limit=2');
        $this->assertSame(['Message 5', 'Message 4'], array_column($first['items'], 'body'));
        $this->assertTrue($first['hasMore']);

        $second = $this->history($token, '?limit=2&before='.$first['items'][1]['id']);
        $this->assertSame(['Message 3', 'Message 2'], array_column($second['items'], 'body'));
        $this->assertTrue($second['hasMore']);

        $third = $this->history($token, '?limit=2&before='.$second['items'][1]['id']);
        $this->assertSame(['Message 1'], array_column($third['items'], 'body'));
        $this->assertFalse($third['hasMore']);
    }

    public function test_a_page_that_ends_exactly_on_the_oldest_message_reports_no_more(): void
    {
        foreach (range(1, 2) as $n) {
            $this->send($this->candidate['accessToken'], "Message $n")->assertCreated();
            $this->travel(1)->seconds();
        }

        $page = $this->history($this->candidate['accessToken'], '?limit=2');

        $this->assertCount(2, $page['items']);
        $this->assertFalse($page['hasMore']);
    }

    public function test_sending_the_same_message_twice_stores_it_once(): void
    {
        Event::fake([MessageSent::class]);
        $clientId = (string) Str::uuid();
        $token = $this->candidate['accessToken'];

        $first = $this->send($token, 'Hello', $clientId)->assertCreated();
        // The retry: 200, not 201, and the same message.
        $second = $this->send($token, 'Hello', $clientId)->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('messages', 1);
        // The other side is not told twice.
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }

    public function test_the_same_client_id_from_the_other_participant_is_a_different_message(): void
    {
        $clientId = (string) Str::uuid();

        $this->send($this->candidate['accessToken'], 'From Ahmed', $clientId)->assertCreated();
        $this->send($this->recruiter['accessToken'], 'From Sara', $clientId)->assertCreated();

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_a_retry_of_an_old_message_still_answers_after_the_chat_closed(): void
    {
        $clientId = (string) Str::uuid();
        $token = $this->candidate['accessToken'];
        $first = $this->send($token, 'Thanks!', $clientId)->assertCreated();
        $this->setStatus(SessionStatus::Completed);

        // The app never got the 201 and retries after the session ended. It must learn the
        // message was delivered, not get "chat closed".
        $this->assertSame($first->json(), $this->send($token, 'Thanks!', $clientId)->assertOk()->json());
    }

    #[DataProvider('closedStatuses')]
    public function test_messages_cannot_be_sent_once_the_session_is_over_but_can_still_be_read(SessionStatus $status): void
    {
        $token = $this->candidate['accessToken'];
        $this->send($token, 'Before it ended')->assertCreated();
        $this->setStatus($status);

        $this->assertProblem($this->send($token, 'Too late'), 409, 'CHAT_CLOSED');

        $this->assertSame(['Before it ended'], array_column($this->history($token)['items'], 'body'));
        $this->assertDatabaseCount('messages', 1);
    }

    /** @return array<string, array{SessionStatus}> */
    public static function closedStatuses(): array
    {
        return [
            'completed' => [SessionStatus::Completed],
            'cancelled' => [SessionStatus::Cancelled],
            'no show' => [SessionStatus::NoShow],
            'disputed' => [SessionStatus::Disputed],
        ];
    }

    public function test_the_chat_is_open_while_the_session_is_in_progress(): void
    {
        $this->setStatus(SessionStatus::InProgress);

        $this->send($this->recruiter['accessToken'], 'Tell me about yourself.')->assertCreated();
    }

    public function test_an_unpaid_session_has_no_chat(): void
    {
        $setup = $this->bookable('Mona Adel', start: '+3 days 10:00', end: '+3 days 11:00');
        $unpaid = $this->book($this->candidate['accessToken'], $setup['body'])->assertCreated()->json('id');

        $this->assertProblem($this->send($this->candidate['accessToken'], 'Hello?', sessionId: $unpaid), 409, 'CHAT_CLOSED');
        // The recruiter cannot even see an unpaid session.
        $this->assertProblem($this->send($setup['recruiter']['accessToken'], 'Hello?', sessionId: $unpaid), 404, 'NOT_FOUND');
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('invalidMessages')]
    public function test_invalid_messages_are_rejected(array $body, string $field): void
    {
        $response = $this->api('POST', "/sessions/{$this->sessionId}/messages", $body, $this->candidate['accessToken']);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
        $this->assertDatabaseCount('messages', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidMessages(): array
    {
        $id = '01a1c737-b065-7130-8d08-f913763e916c';

        return [
            'no body' => [['clientMessageId' => $id], 'body'],
            'only spaces' => [['clientMessageId' => $id, 'body' => '     '], 'body'],
            'over 4000 characters' => [['clientMessageId' => $id, 'body' => str_repeat('a', 4001)], 'body'],
            'no client id' => [['body' => 'Hello'], 'clientMessageId'],
            'client id that is not a uuid' => [['clientMessageId' => 'abc', 'body' => 'Hello'], 'clientMessageId'],
        ];
    }

    public function test_a_message_of_exactly_4000_characters_is_accepted_and_arabic_counts_by_character(): void
    {
        // 4000 Arabic letters are 8000 bytes: the limit must count characters, not bytes.
        $this->send($this->candidate['accessToken'], str_repeat('ع', 4000))->assertCreated();
    }

    public function test_message_text_is_stored_as_is_and_not_treated_as_html(): void
    {
        $text = '<script>alert(1)</script> & "quotes"';

        $this->send($this->candidate['accessToken'], $text)->assertCreated()->assertJson(['body' => $text]);
    }

    public function test_outsiders_cannot_read_or_write_the_chat(): void
    {
        $this->send($this->candidate['accessToken'], 'Private')->assertCreated();
        $stranger = $this->register()['accessToken'];
        $otherRecruiter = $this->recruiter('Mona Adel')['accessToken'];
        $uri = "/sessions/{$this->sessionId}/messages";

        foreach ([$stranger, $otherRecruiter] as $token) {
            $this->assertProblem($this->api('GET', $uri, token: $token), 404, 'NOT_FOUND');
            $this->assertProblem($this->send($token, 'Let me in'), 404, 'NOT_FOUND');
        }
        $this->assertProblem($this->api('GET', $uri), 401, 'TOKEN_INVALID');
        $this->assertProblem($this->api('GET', '/sessions/not-a-uuid/messages', token: $stranger), 404, 'NOT_FOUND');
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_an_admin_can_read_a_chat_but_not_write_in_it(): void
    {
        $this->send($this->candidate['accessToken'], 'Hello')->assertCreated();
        User::factory()->admin()->create(['email' => 'admin@example.com']);
        $admin = $this->api('POST', '/auth/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD])->json('accessToken');

        $this->assertSame(['Hello'], array_column($this->history($admin)['items'], 'body'));
        $this->assertProblem($this->send($admin, 'Admin here'), 403, 'FORBIDDEN_ROLE');
    }

    public function test_invalid_history_parameters_are_rejected(): void
    {
        $token = $this->candidate['accessToken'];
        $uri = "/sessions/{$this->sessionId}/messages";

        foreach (['limit=0' => 'limit', 'limit=101' => 'limit', 'before=abc' => 'before'] as $query => $field) {
            $response = $this->api('GET', "$uri?$query", token: $token);

            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame([$field], array_keys($response->json('errors')));
        }
    }

    public function test_a_user_can_send_at_most_30_messages_a_minute(): void
    {
        config(['careeredge.messages_per_minute' => 3]);
        $token = $this->candidate['accessToken'];
        foreach (range(1, 3) as $n) {
            $this->send($token, "Message $n")->assertCreated();
        }

        $blocked = $this->send($token, 'One too many');

        $this->assertProblem($blocked, 429, 'RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        // The limit is per user: the other participant can still write.
        $this->send($this->recruiter['accessToken'], 'Still here')->assertCreated();
        $this->assertSame(4, Message::count());
    }

    public function test_only_people_who_may_see_the_session_can_subscribe_to_its_live_channel(): void
    {
        // Channel permissions are checked by the real broadcaster, so switch from the test
        // default ("null", which lets everyone in) to Reverb's and load the channel rules into it.
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');

        $subscribe = fn (?string $token, ?string $sessionId = null) => $this->api('POST', '/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-session.'.($sessionId ?? $this->sessionId),
        ], $token);

        // The two participants get a signed pass for the channel.
        foreach ([$this->candidate['accessToken'], $this->recruiter['accessToken']] as $token) {
            $auth = $subscribe($token)->assertOk()->json('auth');
            $this->assertStringStartsWith('test-key:', $auth);
        }

        // Everyone else is refused.
        $this->assertSame(403, $subscribe($this->register()['accessToken'])->status());
        $this->assertSame(403, $subscribe($this->recruiter('Mona Adel')['accessToken'])->status());
        $this->assertSame(403, $subscribe($this->candidate['accessToken'], '01a1c737-b065-7130-8d08-f913763e916c')->status());
        $this->assertSame(403, $subscribe($this->candidate['accessToken'], 'not-a-uuid')->status());
        $this->assertProblem($subscribe(null), 401, 'TOKEN_INVALID');
    }
}
