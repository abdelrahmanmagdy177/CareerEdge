<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

class SlotsTest extends TestCase
{
    use CreatesRecruiters, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed "now", so "+1 day 16:00" means the same thing on every run.
        $this->travelTo('2026-11-15 10:00:00');
    }

    private function myRange(string $token, string $from = '2026-11-15T00:00:00Z', string $to = '2026-11-30T00:00:00Z'): array
    {
        return $this->api('GET', '/me/slots?from='.urlencode($from).'&to='.urlencode($to), token: $token)->assertOk()->json();
    }

    public function test_a_recruiter_publishes_several_slots_at_once(): void
    {
        $auth = $this->recruiter();

        $response = $this->api('POST', '/me/slots', ['slots' => [
            ['startsAt' => '2026-11-16T16:00:00Z', 'endsAt' => '2026-11-16T17:00:00Z'],
            ['startsAt' => '2026-11-17T09:00:00Z', 'endsAt' => '2026-11-17T09:30:00Z'],
        ]], $auth['accessToken'])->assertCreated();

        $this->assertCount(2, $response->json());
        $response->assertJson([[
            'recruiterId' => $auth['user']['id'],
            'startsAt' => '2026-11-16T16:00:00Z',
            'endsAt' => '2026-11-16T17:00:00Z',
            'status' => 'open',
        ]]);
        $this->assertSame(['id', 'recruiterId', 'startsAt', 'endsAt', 'status'], array_keys($response->json(0)));
    }

    public function test_times_in_another_timezone_are_stored_as_the_same_moment_in_utc(): void
    {
        $token = $this->recruiter()['accessToken'];

        // 18:00 in Cairo (+02:00) is 16:00 UTC.
        $this->api('POST', '/me/slots', ['slots' => [
            ['startsAt' => '2026-11-16T18:00:00+02:00', 'endsAt' => '2026-11-16T19:00:00+02:00'],
        ]], $token)->assertCreated()->assertJson([['startsAt' => '2026-11-16T16:00:00Z', 'endsAt' => '2026-11-16T17:00:00Z']]);
    }

    /** @param  array{startsAt: string, endsAt: string}  $slot */
    #[DataProvider('invalidSlots')]
    public function test_invalid_slots_are_rejected_and_the_error_names_the_row(array $slot, string $field): void
    {
        $token = $this->recruiter()['accessToken'];
        $good = ['startsAt' => '2026-11-18T10:00:00Z', 'endsAt' => '2026-11-18T11:00:00Z'];

        $response = $this->api('POST', '/me/slots', ['slots' => [$good, $slot]], $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(["slots.1.$field"], array_keys($response->json('errors')));
        // All or nothing: the good slot was not saved either.
        $this->assertDatabaseCount('availability_slots', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidSlots(): array
    {
        return [
            'starts in the past' => [['startsAt' => '2026-11-15T09:00:00Z', 'endsAt' => '2026-11-15T10:30:00Z'], 'startsAt'],
            'starts exactly now' => [['startsAt' => '2026-11-15T10:00:00Z', 'endsAt' => '2026-11-15T11:00:00Z'], 'startsAt'],
            'shorter than 30 minutes' => [['startsAt' => '2026-11-16T16:00:00Z', 'endsAt' => '2026-11-16T16:29:00Z'], 'endsAt'],
            'longer than 4 hours' => [['startsAt' => '2026-11-16T12:00:00Z', 'endsAt' => '2026-11-16T16:01:00Z'], 'endsAt'],
            'ends before it starts' => [['startsAt' => '2026-11-16T16:00:00Z', 'endsAt' => '2026-11-16T15:00:00Z'], 'endsAt'],
            'start is not a date' => [['startsAt' => 'tomorrow-ish', 'endsAt' => '2026-11-16T15:00:00Z'], 'startsAt'],
            'no end' => [['startsAt' => '2026-11-16T16:00:00Z'], 'endsAt'],
        ];
    }

    public function test_exactly_30_minutes_and_exactly_4_hours_are_allowed(): void
    {
        $token = $this->recruiter()['accessToken'];

        $this->api('POST', '/me/slots', ['slots' => [
            ['startsAt' => '2026-11-16T08:00:00Z', 'endsAt' => '2026-11-16T08:30:00Z'],
            ['startsAt' => '2026-11-16T12:00:00Z', 'endsAt' => '2026-11-16T16:00:00Z'],
        ]], $token)->assertCreated();
    }

    public function test_an_empty_list_or_more_than_50_slots_is_rejected(): void
    {
        $token = $this->recruiter()['accessToken'];
        $many = array_map(fn (int $i) => [
            'startsAt' => now()->addDays(1 + $i)->utc()->format('Y-m-d\T10:00:00\Z'),
            'endsAt' => now()->addDays(1 + $i)->utc()->format('Y-m-d\T11:00:00\Z'),
        ], range(0, 50));

        $this->assertProblem($this->api('POST', '/me/slots', ['slots' => []], $token), 400, 'VALIDATION_FAILED');
        $this->assertProblem($this->api('POST', '/me/slots', ['slots' => $many], $token), 400, 'VALIDATION_FAILED');
        $this->api('POST', '/me/slots', ['slots' => array_slice($many, 0, 50)], $token)->assertCreated();
    }

    #[DataProvider('overlaps')]
    public function test_a_slot_that_overlaps_an_existing_one_is_refused(string $start, string $end): void
    {
        $token = $this->recruiter()['accessToken'];
        $this->slot($token, '+1 day 16:00', '+1 day 17:00');

        $response = $this->api('POST', '/me/slots', ['slots' => [
            // A fine slot first: it must not be saved when the second one fails.
            $this->slotBody('+3 days 10:00', '+3 days 11:00'),
            $this->slotBody($start, $end),
        ]], $token);

        $this->assertProblem($response, 409, 'SLOT_OVERLAP');
        $this->assertDatabaseCount('availability_slots', 1);
    }

    /** @return array<string, array{string, string}> */
    public static function overlaps(): array
    {
        return [
            'same time' => ['+1 day 16:00', '+1 day 17:00'],
            'starts inside' => ['+1 day 16:30', '+1 day 17:30'],
            'ends inside' => ['+1 day 15:30', '+1 day 16:30'],
            'surrounds it' => ['+1 day 15:00', '+1 day 18:00'],
            'inside it' => ['+1 day 16:10', '+1 day 16:50'],
        ];
    }

    public function test_slots_that_only_touch_are_allowed(): void
    {
        $token = $this->recruiter()['accessToken'];
        $this->slot($token, '+1 day 16:00', '+1 day 17:00');

        // One ends at 16:00 when the next starts, and one starts at 17:00 when the previous ends.
        $this->slot($token, '+1 day 15:00', '+1 day 16:00');
        $this->slot($token, '+1 day 17:00', '+1 day 18:00');

        $this->assertDatabaseCount('availability_slots', 3);
    }

    public function test_two_slots_in_the_same_request_cannot_overlap_each_other(): void
    {
        $token = $this->recruiter()['accessToken'];

        $response = $this->api('POST', '/me/slots', ['slots' => [
            $this->slotBody('+1 day 16:30', '+1 day 17:30'),
            $this->slotBody('+1 day 16:00', '+1 day 17:00'),
        ]], $token);

        $this->assertProblem($response, 409, 'SLOT_OVERLAP');
        $this->assertDatabaseCount('availability_slots', 0);
    }

    public function test_two_recruiters_can_be_free_at_the_same_time(): void
    {
        $this->slot($this->recruiter()['accessToken'], '+1 day 16:00', '+1 day 17:00');
        $this->slot($this->recruiter('Mona Adel')['accessToken'], '+1 day 16:00', '+1 day 17:00');

        $this->assertDatabaseCount('availability_slots', 2);
    }

    public function test_the_database_itself_refuses_overlapping_slots_even_when_the_app_check_is_skipped(): void
    {
        $auth = $this->recruiter();
        $this->slot($auth['accessToken'], '+1 day 16:00', '+1 day 17:00');

        try {
            // Straight to the model, as a second request would do if it passed the check at
            // the same instant as the first.
            AvailabilitySlot::create([
                'recruiter_id' => $auth['user']['id'],
                'starts_at' => now()->modify('+1 day 16:30'),
                'ends_at' => now()->modify('+1 day 17:30'),
            ]);
            $this->fail('The overlapping slot was saved.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }
    }

    public function test_my_slots_lists_every_status_inside_the_window_in_time_order(): void
    {
        $auth = $this->recruiter();
        $token = $auth['accessToken'];
        $later = $this->slot($token, '+5 days 10:00', '+5 days 11:00');
        $sooner = $this->slot($token, '+1 day 16:00', '+1 day 17:00');
        $outside = $this->slot($token, '+20 days 10:00', '+20 days 11:00');
        $this->slot($this->recruiter('Mona Adel')['accessToken'], '+2 days 10:00', '+2 days 11:00');
        AvailabilitySlot::whereKey($later['id'])->update(['status' => SlotStatus::Booked]);

        $list = $this->myRange($token);

        $this->assertSame([$sooner['id'], $later['id']], array_column($list, 'id'));
        $this->assertSame(['open', 'booked'], array_column($list, 'status'));
        $this->assertNotContains($outside['id'], array_column($list, 'id'));
    }

    #[DataProvider('badRanges')]
    public function test_listing_needs_a_valid_window_of_at_most_31_days(string $query, string $field): void
    {
        $token = $this->recruiter()['accessToken'];

        $response = $this->api('GET', "/me/slots?$query", token: $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
    }

    /** @return array<string, array{string, string}> */
    public static function badRanges(): array
    {
        return [
            'no from' => ['to=2026-11-20T00:00:00Z', 'from'],
            'no to' => ['from=2026-11-20T00:00:00Z', 'to'],
            'to before from' => ['from=2026-11-20T00:00:00Z&to=2026-11-19T00:00:00Z', 'to'],
            'more than 31 days' => ['from=2026-11-01T00:00:00Z&to=2026-12-02T00:00:01Z', 'to'],
            'from is not a date' => ['from=soon&to=2026-11-19T00:00:00Z', 'from'],
        ];
    }

    public function test_a_window_of_exactly_31_days_is_allowed(): void
    {
        $token = $this->recruiter()['accessToken'];

        $this->assertSame([], $this->myRange($token, '2026-11-01T00:00:00Z', '2026-12-02T00:00:00Z'));
    }

    public function test_an_open_slot_can_be_deleted(): void
    {
        $token = $this->recruiter()['accessToken'];
        $slot = $this->slot($token, '+1 day 16:00', '+1 day 17:00');

        $this->api('DELETE', "/me/slots/{$slot['id']}", token: $token)->assertNoContent();

        $this->assertDatabaseCount('availability_slots', 0);
        $this->assertProblem($this->api('DELETE', "/me/slots/{$slot['id']}", token: $token), 404, 'NOT_FOUND');
    }

    #[DataProvider('takenStatuses')]
    public function test_a_held_or_booked_slot_cannot_be_deleted(SlotStatus $status): void
    {
        $token = $this->recruiter()['accessToken'];
        $slot = $this->slot($token, '+1 day 16:00', '+1 day 17:00');
        AvailabilitySlot::whereKey($slot['id'])->update(['status' => $status]);

        $this->assertProblem($this->api('DELETE', "/me/slots/{$slot['id']}", token: $token), 409, 'SLOT_UNAVAILABLE');
        $this->assertDatabaseCount('availability_slots', 1);
    }

    /** @return array<string, array{SlotStatus}> */
    public static function takenStatuses(): array
    {
        return ['held' => [SlotStatus::Held], 'booked' => [SlotStatus::Booked]];
    }

    public function test_a_recruiter_cannot_delete_another_recruiters_slot(): void
    {
        $slot = $this->slot($this->recruiter()['accessToken'], '+1 day 16:00', '+1 day 17:00');
        $mona = $this->recruiter('Mona Adel')['accessToken'];

        $this->assertProblem($this->api('DELETE', "/me/slots/{$slot['id']}", token: $mona), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('DELETE', '/me/slots/not-a-uuid', token: $mona), 404, 'NOT_FOUND');
        $this->assertDatabaseCount('availability_slots', 1);
    }

    public function test_only_approved_recruiters_can_publish_slots(): void
    {
        $pending = $this->recruiter(status: VerificationStatus::Pending)['accessToken'];
        $candidate = $this->register()['accessToken'];
        $body = ['slots' => [$this->slotBody('+1 day 16:00', '+1 day 17:00')]];

        $this->assertProblem($this->api('POST', '/me/slots', $body, $pending), 403, 'RECRUITER_NOT_APPROVED');
        $this->assertProblem($this->api('POST', '/me/slots', $body, $candidate), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('POST', '/me/slots', $body), 401, 'TOKEN_INVALID');
        $this->assertDatabaseCount('availability_slots', 0);
    }
}
