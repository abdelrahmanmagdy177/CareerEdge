<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Scheduling\Models\Offering;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

class OfferingsTest extends TestCase
{
    use CreatesRecruiters, RefreshDatabase;

    public function test_an_approved_recruiter_publishes_an_offering(): void
    {
        $auth = $this->recruiter();

        $response = $this->api('POST', '/me/offerings', $this->offeringBody(), $auth['accessToken'])->assertCreated();

        $response->assertExactJson([
            'id' => $response->json('id'),
            'recruiterId' => $auth['user']['id'],
            'sessionType' => 'mock_interview',
            'mode' => 'voice',
            'durationMinutes' => 45,
            'price' => ['amountMinor' => 45000, 'currency' => 'EGP'],
            'isActive' => true,
        ]);
    }

    public function test_the_list_shows_only_my_offerings_including_inactive_ones(): void
    {
        $sara = $this->recruiter()['accessToken'];
        $mona = $this->recruiter('Mona Adel')['accessToken'];
        $voice = $this->offer($sara);
        $chat = $this->offer($sara, ['mode' => 'chat']);
        $this->offer($mona);
        $this->api('PATCH', "/me/offerings/{$chat['id']}", ['isActive' => false], $sara)->assertOk();

        $list = $this->api('GET', '/me/offerings', token: $sara)->assertOk()->json();

        // Active first.
        $this->assertSame([$voice['id'], $chat['id']], array_column($list, 'id'));
        $this->assertSame([true, false], array_column($list, 'isActive'));
    }

    public function test_a_second_active_offering_with_the_same_type_mode_and_duration_is_refused(): void
    {
        $token = $this->recruiter()['accessToken'];
        $this->offer($token);

        $duplicate = $this->api('POST', '/me/offerings', $this->offeringBody(['price' => ['amountMinor' => 60000, 'currency' => 'EGP']]), $token);

        $this->assertProblem($duplicate, 409, 'OFFERING_EXISTS');
        $this->assertDatabaseCount('offerings', 1);

        // Changing any one of the three makes it a different offering.
        $this->offer($token, ['durationMinutes' => 60]);
        $this->offer($token, ['mode' => 'chat']);
        $this->offer($token, ['sessionType' => 'cv_review']);
    }

    public function test_two_recruiters_can_publish_the_same_kind_of_offering(): void
    {
        $this->offer($this->recruiter()['accessToken']);
        $this->offer($this->recruiter('Mona Adel')['accessToken']);

        $this->assertDatabaseCount('offerings', 2);
    }

    public function test_after_switching_one_off_the_same_kind_can_be_published_again_but_not_both_switched_on(): void
    {
        $token = $this->recruiter()['accessToken'];
        $old = $this->offer($token);
        $this->api('PATCH', "/me/offerings/{$old['id']}", ['isActive' => false], $token)
            ->assertOk()->assertJson(['isActive' => false]);

        $this->offer($token);

        // Switching the old one back on would make two active copies.
        $this->assertProblem($this->api('PATCH', "/me/offerings/{$old['id']}", ['isActive' => true], $token), 409, 'OFFERING_EXISTS');
        $this->assertFalse(Offering::findOrFail($old['id'])->is_active);
    }

    public function test_the_database_itself_refuses_two_active_copies(): void
    {
        $auth = $this->recruiter();
        $this->offer($auth['accessToken']);
        $this->expectException(UniqueConstraintViolationException::class);

        // Straight to the table, skipping the controller: the rule must hold anyway.
        DB::table('offerings')->insert([
            'id' => '01a1c737-b065-7130-8d08-f913763e916c', 'recruiter_id' => $auth['user']['id'],
            'session_type' => 'mock_interview', 'mode' => 'voice', 'duration_minutes' => 45,
            'price_minor' => 50000, 'currency' => 'EGP', 'is_active' => true,
        ]);
    }

    public function test_the_price_can_be_changed(): void
    {
        $token = $this->recruiter()['accessToken'];
        $offering = $this->offer($token);

        $this->api('PATCH', "/me/offerings/{$offering['id']}", ['price' => ['amountMinor' => 60000, 'currency' => 'EGP']], $token)
            ->assertOk()
            ->assertJson(['price' => ['amountMinor' => 60000, 'currency' => 'EGP'], 'isActive' => true, 'durationMinutes' => 45]);
    }

    public function test_patch_cannot_change_type_mode_or_duration(): void
    {
        $token = $this->recruiter()['accessToken'];
        $offering = $this->offer($token);

        $this->api('PATCH', "/me/offerings/{$offering['id']}", [
            'isActive' => true, 'sessionType' => 'cv_review', 'mode' => 'chat', 'durationMinutes' => 30,
        ], $token)->assertOk()->assertJson(['sessionType' => 'mock_interview', 'mode' => 'voice', 'durationMinutes' => 45]);
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('invalidOfferings')]
    public function test_invalid_offerings_are_rejected_with_the_field_name(array $overrides, string $field): void
    {
        $token = $this->recruiter()['accessToken'];

        $response = $this->api('POST', '/me/offerings', $this->offeringBody($overrides), $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
        $this->assertDatabaseCount('offerings', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidOfferings(): array
    {
        return [
            'unknown session type' => [['sessionType' => 'coaching'], 'sessionType'],
            'unknown mode' => [['mode' => 'video'], 'mode'],
            'duration that is not 30, 45 or 60' => [['durationMinutes' => 20], 'durationMinutes'],
            'no price' => [['price' => null], 'price'],
            'price below 50 EGP' => [['price' => ['amountMinor' => 4999, 'currency' => 'EGP']], 'price.amountMinor'],
            'price above 10,000 EGP' => [['price' => ['amountMinor' => 1000001, 'currency' => 'EGP']], 'price.amountMinor'],
            'price with a fraction' => [['price' => ['amountMinor' => 45000.5, 'currency' => 'EGP']], 'price.amountMinor'],
            'price as text' => [['price' => ['amountMinor' => '45000', 'currency' => 'EGP']], 'price.amountMinor'],
            'another currency' => [['price' => ['amountMinor' => 45000, 'currency' => 'USD']], 'price.currency'],
            'no currency' => [['price' => ['amountMinor' => 45000]], 'price.currency'],
        ];
    }

    public function test_the_lowest_and_highest_allowed_prices_are_accepted(): void
    {
        $token = $this->recruiter()['accessToken'];

        $this->offer($token, ['price' => ['amountMinor' => 5000, 'currency' => 'EGP']]);
        $this->offer($token, ['mode' => 'chat', 'price' => ['amountMinor' => 1000000, 'currency' => 'EGP']]);

        $this->assertDatabaseCount('offerings', 2);
    }

    public function test_patch_rejects_an_empty_body_and_bad_values(): void
    {
        $token = $this->recruiter()['accessToken'];
        $offering = $this->offer($token);
        $uri = "/me/offerings/{$offering['id']}";

        $empty = $this->api('PATCH', $uri, [], $token);
        $this->assertProblem($empty, 400, 'VALIDATION_FAILED');
        $this->assertSame(['body'], array_keys($empty->json('errors')));

        $this->assertProblem($this->api('PATCH', $uri, ['isActive' => 'yes'], $token), 400, 'VALIDATION_FAILED');
        $this->assertProblem($this->api('PATCH', $uri, ['price' => ['amountMinor' => 10, 'currency' => 'EGP']], $token), 400, 'VALIDATION_FAILED');
        $this->assertSame(45000, Offering::findOrFail($offering['id'])->price_minor);
    }

    public function test_a_recruiter_cannot_change_another_recruiters_offering(): void
    {
        $offering = $this->offer($this->recruiter()['accessToken']);
        $mona = $this->recruiter('Mona Adel')['accessToken'];

        $this->assertProblem($this->api('PATCH', "/me/offerings/{$offering['id']}", ['isActive' => false], $mona), 404, 'NOT_FOUND');
        $this->assertTrue(Offering::findOrFail($offering['id'])->is_active);
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $token = $this->recruiter()['accessToken'];

        $this->assertProblem($this->api('PATCH', '/me/offerings/01a1c737-b065-7130-8d08-f913763e916c', ['isActive' => false], $token), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('PATCH', '/me/offerings/123', ['isActive' => false], $token), 404, 'NOT_FOUND');
    }

    #[DataProvider('notApproved')]
    public function test_a_recruiter_who_is_not_approved_cannot_publish(VerificationStatus $status): void
    {
        $token = $this->recruiter(status: $status)['accessToken'];

        $this->assertProblem($this->api('POST', '/me/offerings', $this->offeringBody(), $token), 403, 'RECRUITER_NOT_APPROVED');
        // They can still open the screen.
        $this->api('GET', '/me/offerings', token: $token)->assertOk()->assertExactJson([]);
    }

    /** @return array<string, array{VerificationStatus}> */
    public static function notApproved(): array
    {
        return ['pending' => [VerificationStatus::Pending], 'rejected' => [VerificationStatus::Rejected]];
    }

    public function test_a_recruiter_without_a_profile_cannot_publish(): void
    {
        $token = $this->register(['role' => 'recruiter'])['accessToken'];

        $this->assertProblem($this->api('POST', '/me/offerings', $this->offeringBody(), $token), 403, 'RECRUITER_NOT_APPROVED');
    }

    public function test_candidates_and_guests_have_no_access(): void
    {
        $candidate = $this->register()['accessToken'];

        $this->assertProblem($this->api('GET', '/me/offerings', token: $candidate), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('POST', '/me/offerings', $this->offeringBody(), $candidate), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('GET', '/me/offerings'), 401, 'TOKEN_INVALID');
    }
}
