<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Models\RecruiterProfile;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Session;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BooksSessions;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use BooksSessions, CreatesRecruiters, RefreshDatabase;

    private const UNKNOWN = '01a1c737-b065-7130-8d08-f913763e916c';

    /** @var array<string, mixed> */
    private array $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-11-15 10:00:00');
        $this->candidate = $this->register(['fullName' => 'Ahmed Hassan']);
    }

    private function token(): string
    {
        return $this->candidate['accessToken'];
    }

    private function uploadCv(string $token): string
    {
        $file = UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.7\ncv");

        return $this->asFreshClient($token)
            ->post('/api/v1/me/cvs', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('id');
    }

    public function test_a_candidate_books_a_session_that_waits_for_payment(): void
    {
        $backend = $this->specialization('backend');
        $this->api('PUT', '/me/candidate-profile', [
            'specializationId' => $backend->id, 'targetRole' => 'Backend Engineer', 'yearsOfExperience' => 3,
        ], $this->token())->assertOk();
        // A 60-minute slot and a 45-minute offering.
        $setup = $this->bookable();

        $response = $this->book($this->token(), $setup['body'])->assertCreated();

        $response->assertExactJson([
            'id' => $response->json('id'),
            'type' => 'mock_interview',
            'mode' => 'voice',
            'status' => 'pending_payment',
            'candidate' => ['userId' => $this->candidate['user']['id'], 'fullName' => 'Ahmed Hassan', 'avatarUrl' => null],
            'recruiter' => ['userId' => $setup['recruiter']['user']['id'], 'fullName' => 'Sara Mostafa', 'avatarUrl' => null],
            'specialization' => ['id' => $backend->id, 'slug' => 'backend', 'name' => 'Backend', 'parentId' => null],
            // Starts when the slot starts and lasts as long as the offering, not the slot.
            'scheduledStart' => '2026-11-16T16:00:00Z',
            'scheduledEnd' => '2026-11-16T16:45:00Z',
            'durationMinutes' => 45,
            'actualStart' => null,
            'actualEnd' => null,
            'holdExpiresAt' => '2026-11-15T10:15:00Z',
            'price' => ['amountMinor' => 45000, 'currency' => 'EGP'],
            'paymentStatus' => null,
            'cv' => null,
            'candidateNotes' => null,
            'chatOpen' => false,
            'canJoinFrom' => '2026-11-16T15:50:00Z',
            'cancelledBy' => null,
            'cancelReason' => null,
            'hasReview' => false,
            'hasScorecard' => false,
            'createdAt' => '2026-11-15T10:00:00Z',
        ]);

        $this->assertSame(SlotStatus::Held, AvailabilitySlot::findOrFail($setup['slot']['id'])->status);
    }

    public function test_a_held_slot_disappears_from_the_recruiters_public_slots(): void
    {
        $setup = $this->bookable();
        $uri = "/recruiters/{$setup['recruiter']['user']['id']}/slots?from=2026-11-15T00:00:00Z&to=2026-11-30T00:00:00Z";
        $this->assertCount(1, $this->api('GET', $uri, token: $this->token())->json());

        $this->book($this->token(), $setup['body'])->assertCreated();

        $this->assertSame([], $this->api('GET', $uri, token: $this->token())->json());
    }

    public function test_a_session_can_carry_a_cv_and_notes(): void
    {
        $cvId = $this->uploadCv($this->token());
        $setup = $this->bookable();

        $session = $this->book($this->token(), [
            ...$setup['body'], 'cvId' => $cvId, 'candidateNotes' => 'Interview at a fintech next week.',
        ])->assertCreated()->json();

        $this->assertSame($cvId, $session['cv']['id']);
        $this->assertSame('cv.pdf', $session['cv']['fileName']);
        $this->assertSame('Interview at a fintech next week.', $session['candidateNotes']);
        $this->assertNull($session['specialization']); // this candidate has no profile yet
    }

    public function test_a_cv_review_needs_a_cv(): void
    {
        $setup = $this->bookable(offering: ['sessionType' => 'cv_review']);

        $response = $this->book($this->token(), $setup['body']);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['cvId'], array_keys($response->json('errors')));
        $this->assertSame(SlotStatus::Open, AvailabilitySlot::findOrFail($setup['slot']['id'])->status);
    }

    public function test_only_my_own_undeleted_cv_can_be_attached(): void
    {
        $setup = $this->bookable();
        $someoneElses = $this->uploadCv($this->register()['accessToken']);
        $deleted = $this->uploadCv($this->token());
        $this->api('DELETE', "/me/cvs/$deleted", token: $this->token())->assertNoContent();

        foreach ([$someoneElses, $deleted, self::UNKNOWN] as $cvId) {
            $response = $this->book($this->token(), [...$setup['body'], 'cvId' => $cvId]);

            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame(['cvId'], array_keys($response->json('errors')));
        }
        $this->assertDatabaseCount('session_bookings', 0);
    }

    public function test_a_slot_shorter_than_the_offering_is_rejected(): void
    {
        $setup = $this->bookable(offering: ['durationMinutes' => 60], start: '+1 day 16:00', end: '+1 day 16:45');

        $response = $this->book($this->token(), $setup['body']);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['slotId'], array_keys($response->json('errors')));
    }

    public function test_an_offering_that_exactly_fills_the_slot_is_fine(): void
    {
        $setup = $this->bookable(offering: ['durationMinutes' => 60]);

        $this->book($this->token(), $setup['body'])->assertCreated()->assertJson(['scheduledEnd' => '2026-11-16T17:00:00Z']);
    }

    public function test_things_that_do_not_exist_or_do_not_belong_together_are_a_404(): void
    {
        $sara = $this->bookable('Sara Mostafa');
        $mona = $this->bookable('Mona Adel', start: '+2 days 10:00', end: '+2 days 11:00');

        $cases = [
            'unknown offering' => ['offeringId' => self::UNKNOWN, 'slotId' => $sara['slot']['id']],
            'unknown slot' => ['offeringId' => $sara['offering']['id'], 'slotId' => self::UNKNOWN],
            "another recruiter's slot" => ['offeringId' => $sara['offering']['id'], 'slotId' => $mona['slot']['id']],
        ];

        foreach ($cases as $name => $body) {
            $this->assertProblem($this->book($this->token(), $body), 404, 'NOT_FOUND');
        }
        $this->assertDatabaseCount('session_bookings', 0);
        $this->assertSame(2, AvailabilitySlot::where('status', SlotStatus::Open)->count());
    }

    public function test_an_offering_that_was_switched_off_cannot_be_booked(): void
    {
        $setup = $this->bookable();
        $this->api('PATCH', "/me/offerings/{$setup['offering']['id']}", ['isActive' => false], $setup['recruiter']['accessToken'])->assertOk();

        $this->assertProblem($this->book($this->token(), $setup['body']), 404, 'NOT_FOUND');
    }

    public function test_a_recruiter_who_lost_approval_cannot_be_booked(): void
    {
        $setup = $this->bookable();
        RecruiterProfile::whereKey($setup['recruiter']['user']['id'])->update(['verification_status' => VerificationStatus::Rejected]);

        $this->assertProblem($this->book($this->token(), $setup['body']), 404, 'NOT_FOUND');
    }

    public function test_a_slot_that_someone_else_is_paying_for_is_unavailable(): void
    {
        $setup = $this->bookable();
        $this->book($this->register()['accessToken'], $setup['body'])->assertCreated();

        $this->assertProblem($this->book($this->token(), $setup['body']), 409, 'SLOT_UNAVAILABLE');
        $this->assertDatabaseCount('session_bookings', 1);
    }

    public function test_a_slot_that_already_started_is_unavailable(): void
    {
        $setup = $this->bookable(start: '+2 hours', end: '+3 hours');

        $this->travelTo('2026-11-15 12:00:00');
        $late = $this->register()['accessToken'];

        $this->assertProblem($this->book($late, $setup['body']), 409, 'SLOT_UNAVAILABLE');
    }

    public function test_a_candidate_cannot_have_two_sessions_at_the_same_time(): void
    {
        $sara = $this->bookable('Sara Mostafa', start: '+1 day 16:00', end: '+1 day 17:00');
        // Mona's slot starts at 16:30, in the middle of the 16:00 to 16:45 session with Sara.
        $mona = $this->bookable('Mona Adel', start: '+1 day 16:30', end: '+1 day 17:30');
        $this->book($this->token(), $sara['body'])->assertCreated();

        $this->assertProblem($this->book($this->token(), $mona['body']), 409, 'CANDIDATE_TIME_CONFLICT');

        // The refused booking did not take Mona's slot away from others.
        $this->assertSame(SlotStatus::Open, AvailabilitySlot::findOrFail($mona['slot']['id'])->status);
        $this->book($this->register()['accessToken'], $mona['body'])->assertCreated();
    }

    public function test_sessions_that_follow_each_other_exactly_are_allowed(): void
    {
        $sara = $this->bookable('Sara Mostafa', start: '+1 day 16:00', end: '+1 day 17:00');
        // Starts at 16:45, the minute the 45-minute session with Sara ends.
        $mona = $this->bookable('Mona Adel', start: '+1 day 16:45', end: '+1 day 17:45');

        $this->book($this->token(), $sara['body'])->assertCreated();
        $this->book($this->token(), $mona['body'])->assertCreated();
    }

    public function test_the_database_itself_refuses_overlapping_sessions_for_one_candidate(): void
    {
        $sara = $this->bookable('Sara Mostafa');
        $mona = $this->bookable('Mona Adel', start: '+1 day 16:30', end: '+1 day 17:30');
        $session = Session::findOrFail($this->book($this->token(), $sara['body'])->json('id'));

        try {
            // A copy of the session at an overlapping time, saved without the app's check.
            $session->replicate()->fill([
                'slot_id' => $mona['slot']['id'],
                'scheduled_start' => '2026-11-16 16:30:00',
                'scheduled_end' => '2026-11-16 17:15:00',
            ])->save();
            $this->fail('The overlapping session was saved.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }
    }

    public function test_the_price_is_fixed_at_booking_time(): void
    {
        $setup = $this->bookable();
        $session = $this->book($this->token(), $setup['body'])->json();

        $this->api('PATCH', "/me/offerings/{$setup['offering']['id']}", [
            'price' => ['amountMinor' => 90000, 'currency' => 'EGP'],
        ], $setup['recruiter']['accessToken'])->assertOk();

        $this->api('GET', "/sessions/{$session['id']}", token: $this->token())
            ->assertJson(['price' => ['amountMinor' => 45000, 'currency' => 'EGP']]);
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('invalidBodies')]
    public function test_malformed_input_is_rejected(array $body, string $field): void
    {
        $response = $this->book($this->token(), $body);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): array
    {
        return [
            'no offering' => [['slotId' => self::UNKNOWN], 'offeringId'],
            'no slot' => [['offeringId' => self::UNKNOWN], 'slotId'],
            'offering id that is not a uuid' => [['offeringId' => 'abc', 'slotId' => self::UNKNOWN], 'offeringId'],
            'cv id that is not a uuid' => [['offeringId' => self::UNKNOWN, 'slotId' => self::UNKNOWN, 'cvId' => 'abc'], 'cvId'],
            'notes over 1000 characters' => [['offeringId' => self::UNKNOWN, 'slotId' => self::UNKNOWN, 'candidateNotes' => str_repeat('a', 1001)], 'candidateNotes'],
        ];
    }

    public function test_the_idempotency_key_header_is_required_and_must_be_a_uuid(): void
    {
        $setup = $this->bookable();

        foreach ([[], ['Idempotency-Key' => 'not-a-uuid']] as $headers) {
            $response = $this->api('POST', '/sessions', $setup['body'], $this->token(), $headers);

            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame(['Idempotency-Key'], array_keys($response->json('errors')));
        }
        $this->assertDatabaseCount('session_bookings', 0);
    }

    public function test_sending_the_same_booking_twice_creates_one_session_and_returns_it_both_times(): void
    {
        $setup = $this->bookable();
        $key = (string) Str::uuid();

        $first = $this->book($this->token(), $setup['body'], $key)->assertCreated();
        $second = $this->book($this->token(), $setup['body'], $key)->assertCreated();

        $this->assertSame($first->json(), $second->json());
        $this->assertNull($first->headers->get('Idempotent-Replayed'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replayed'));
        $this->assertDatabaseCount('session_bookings', 1);
    }

    public function test_without_the_same_key_a_second_identical_booking_is_a_real_second_attempt(): void
    {
        $setup = $this->bookable();

        $this->book($this->token(), $setup['body'])->assertCreated();

        // Not a replay: it runs, and finds the slot taken (by the first one).
        $this->assertProblem($this->book($this->token(), $setup['body']), 409, 'CANDIDATE_TIME_CONFLICT');
    }

    public function test_reusing_a_key_for_a_different_request_is_refused(): void
    {
        $sara = $this->bookable('Sara Mostafa');
        $mona = $this->bookable('Mona Adel', start: '+3 days 10:00', end: '+3 days 11:00');
        $key = (string) Str::uuid();
        $this->book($this->token(), $sara['body'], $key)->assertCreated();

        $this->assertProblem($this->book($this->token(), $mona['body'], $key), 422, 'IDEMPOTENCY_KEY_REUSED');
        $this->assertDatabaseCount('session_bookings', 1);
    }

    public function test_a_key_belongs_to_one_user(): void
    {
        $sara = $this->bookable('Sara Mostafa');
        $mona = $this->bookable('Mona Adel', start: '+3 days 10:00', end: '+3 days 11:00');
        $key = (string) Str::uuid();
        $mine = $this->book($this->token(), $sara['body'], $key)->assertCreated()->json('id');

        // Another user who happens to send the same key gets their own booking, not a copy of mine.
        $theirs = $this->book($this->register()['accessToken'], $mona['body'], $key)->assertCreated()->json('id');

        $this->assertNotSame($mine, $theirs);
    }

    public function test_a_failed_attempt_is_not_remembered_so_the_same_key_can_succeed_later(): void
    {
        $setup = $this->bookable();
        $key = (string) Str::uuid();
        $other = $this->register()['accessToken'];
        $taken = $this->book($other, $setup['body'])->assertCreated()->json('id');

        $this->assertProblem($this->book($this->token(), $setup['body'], $key), 409, 'SLOT_UNAVAILABLE');

        // The other candidate never pays, the hold runs out, and the slot is free again.
        $this->travel(16)->minutes();
        $this->artisan('sessions:expire-holds')->assertSuccessful();
        $this->candidate = $this->register(); // tokens expired with the time jump

        $this->assertSame('cancelled', Session::findOrFail($taken)->status->value);
        $this->book($this->token(), $setup['body'], $key)->assertCreated();
    }

    public function test_a_slot_whose_booking_expired_can_be_deleted_by_the_recruiter(): void
    {
        $setup = $this->bookable();
        $sessionId = $this->book($this->token(), $setup['body'])->assertCreated()->json('id');
        $this->travel(16)->minutes();
        $this->artisan('sessions:expire-holds')->assertSuccessful();
        $recruiter = $this->api('POST', '/auth/login', [
            'email' => $setup['recruiter']['user']['email'], 'password' => self::PASSWORD,
        ])->json('accessToken');

        // The cancelled session still remembers this slot. Deleting the slot must not fail.
        $this->api('DELETE', "/me/slots/{$setup['slot']['id']}", token: $recruiter)->assertNoContent();

        // The old session is kept, with its own times, just without the slot.
        $session = Session::findOrFail($sessionId);
        $this->assertNull($session->slot_id);
        $this->assertSame('2026-11-16 16:00:00', $session->scheduled_start->format('Y-m-d H:i:s'));
    }

    public function test_only_candidates_can_book(): void
    {
        $setup = $this->bookable();

        $this->assertProblem($this->book($setup['recruiter']['accessToken'], $setup['body']), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('POST', '/sessions', $setup['body'], headers: ['Idempotency-Key' => (string) Str::uuid()]), 401, 'TOKEN_INVALID');
        $this->assertDatabaseCount('session_bookings', 0);
    }
}
