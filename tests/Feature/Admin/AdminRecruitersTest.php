<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Enums\UserRole;
use App\Modules\Identity\Models\User;
use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Models\RecruiterProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActsAsAdmin;
use Tests\Support\CreatesRecruiters;
use Tests\Support\RunsSessions;
use Tests\TestCase;

class AdminRecruitersTest extends TestCase
{
    use ActsAsAdmin, CreatesRecruiters, RefreshDatabase, RunsSessions;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->admin = $this->adminToken();
    }

    private function pending(string $name): array
    {
        return $this->recruiter($name, status: VerificationStatus::Pending);
    }

    /** @param  array<string, mixed>  $body */
    private function decide(array $recruiter, array $body, ?string $token = null): TestResponse
    {
        return $this->api('POST', "/admin/recruiters/{$recruiter['user']['id']}/verification", $body, $token ?? $this->admin);
    }

    /** @return list<string> */
    private function names(string $query = ''): array
    {
        return array_column($this->api('GET', "/admin/recruiters$query", token: $this->admin)->assertOk()->json('items'), 'fullName');
    }

    // ───────────── The first admin ─────────────

    public function test_the_command_creates_an_admin_who_can_log_in(): void
    {
        $this->artisan('admin:create', ['email' => 'Boss@Example.com', '--name' => 'The Boss', '--password' => 'Str0ngPass'])
            ->expectsOutput('Admin boss@example.com created.')
            ->assertSuccessful();

        $login = $this->api('POST', '/auth/login', ['email' => 'boss@example.com', 'password' => 'Str0ngPass'])->assertOk();

        $login->assertJsonPath('user.role', 'admin');
        $login->assertJsonPath('user.fullName', 'The Boss');
        // An admin has no profile to fill in, so the app must not send them to onboarding.
        $login->assertJsonPath('user.hasProfile', true);
        $this->assertNotSame('Str0ngPass', User::where('email', 'boss@example.com')->value('password'));
    }

    public function test_the_command_asks_for_the_password_when_it_is_not_given(): void
    {
        $this->artisan('admin:create', ['email' => 'asked@example.com'])
            ->expectsQuestion('Password (at least 8 characters, with a letter and a digit)', 'Typed1nSecret')
            ->assertSuccessful();

        $this->api('POST', '/auth/login', ['email' => 'asked@example.com', 'password' => 'Typed1nSecret'])->assertOk();
    }

    /** @param  array<string, string>  $arguments */
    #[DataProvider('badAdmins')]
    public function test_the_command_refuses_bad_input(array $arguments): void
    {
        $this->artisan('admin:create', $arguments)->assertFailed();

        // Only the admin from setUp exists.
        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function badAdmins(): array
    {
        return [
            'an email that is already used' => [['email' => 'ADMIN@example.com', '--password' => 'Str0ngPass']],
            'not an email' => [['email' => 'boss', '--password' => 'Str0ngPass']],
            'a short password' => [['email' => 'boss@example.com', '--password' => 'Ab1']],
            'a password with no digit' => [['email' => 'boss@example.com', '--password' => 'OnlyLetters']],
        ];
    }

    public function test_the_public_api_still_cannot_create_an_admin(): void
    {
        $response = $this->api('POST', '/auth/register', $this->registerBody(['role' => 'admin']));

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
    }

    // ───────────── The review queue ─────────────

    public function test_the_list_shows_recruiters_with_their_full_profile(): void
    {
        $sara = $this->pending('Sara Mostafa');

        $list = $this->api('GET', '/admin/recruiters', token: $this->admin)->assertOk()->json();

        $this->assertSame(['items', 'page', 'pageSize', 'totalCount'], array_keys($list));
        $this->assertSame([1, 20, 1], [$list['page'], $list['pageSize'], $list['totalCount']]);
        // The same shape the recruiter sees on their own profile.
        $own = $this->api('GET', '/me/recruiter-profile', token: $sara['accessToken'])->json();
        $this->assertEquals($own, $list['items'][0]);
        $this->assertSame('pending', $list['items'][0]['verificationStatus']);
    }

    public function test_filter_by_status_and_the_longest_waiting_comes_first(): void
    {
        $this->pending('First In Line');
        $this->travel(1)->hours();
        $this->recruiter('Already Approved');
        $this->travel(1)->hours();
        $this->pending('Second In Line');
        $this->travel(1)->hours();
        $this->recruiter('Was Rejected', status: VerificationStatus::Rejected);
        $this->admin = $this->adminToken();

        $this->assertSame(['First In Line', 'Second In Line'], $this->names('?verificationStatus=pending'));
        $this->assertSame(['Already Approved'], $this->names('?verificationStatus=approved'));
        $this->assertSame(['Was Rejected'], $this->names('?verificationStatus=rejected'));
        $this->assertCount(4, $this->names());
    }

    public function test_a_recruiter_who_resubmits_goes_to_the_back_of_the_queue(): void
    {
        $early = $this->recruiter('Early Bird', status: VerificationStatus::Rejected);
        $this->travel(1)->hours();
        $this->pending('Came Later');
        $this->travel(1)->hours();

        // Early Bird fixes the profile and submits again: pending, but behind Came Later.
        $this->api('PUT', '/me/recruiter-profile', [
            'bio' => 'Technical recruiter for 8 years, now with my LinkedIn profile added as requested.',
            'yearsOfExperience' => 8,
            'specializationIds' => [$this->specialization('backend')->id],
            'timezone' => 'Africa/Cairo',
        ], $this->tokenOf($early))->assertOk();

        $this->assertSame(['Came Later', 'Early Bird'], array_column(
            $this->api('GET', '/admin/recruiters?verificationStatus=pending', token: $this->adminToken())->json('items'),
            'fullName',
        ));
    }

    public function test_the_list_is_paged(): void
    {
        foreach (['One', 'Two', 'Three'] as $i => $name) {
            $this->pending("Recruiter $name");
            $this->travel(1)->minutes();
        }

        $page2 = $this->api('GET', '/admin/recruiters?pageSize=2&page=2', token: $this->admin)->json();

        $this->assertSame(['Recruiter Three'], array_column($page2['items'], 'fullName'));
        $this->assertSame(3, $page2['totalCount']);
    }

    public function test_invalid_list_parameters_are_rejected(): void
    {
        foreach (['verificationStatus=maybe' => 'verificationStatus', 'page=0' => 'page', 'pageSize=101' => 'pageSize'] as $query => $field) {
            $response = $this->api('GET', "/admin/recruiters?$query", token: $this->admin);

            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame([$field], array_keys($response->json('errors')));
        }
    }

    // ───────────── Approve and reject ─────────────

    public function test_approving_lets_the_recruiter_publish_and_be_found(): void
    {
        $sara = $this->pending('Sara Mostafa');
        $candidate = $this->register()['accessToken'];
        $this->assertProblem($this->api('POST', '/me/offerings', $this->offeringBody(), $sara['accessToken']), 403, 'RECRUITER_NOT_APPROVED');

        $this->decide($sara, ['decision' => 'approved'])->assertOk()
            ->assertJson(['userId' => $sara['user']['id'], 'fullName' => 'Sara Mostafa', 'verificationStatus' => 'approved', 'verificationNote' => null]);

        // No new login needed: the very next request sees the approval.
        $this->offer($sara['accessToken']);
        $this->assertSame(['Sara Mostafa'], array_column($this->api('GET', '/recruiters', token: $candidate)->json('items'), 'fullName'));
    }

    public function test_rejecting_needs_a_note_and_the_recruiter_can_read_it(): void
    {
        $sara = $this->pending('Sara Mostafa');

        foreach ([['decision' => 'rejected'], ['decision' => 'rejected', 'note' => ''], ['decision' => 'rejected', 'note' => null]] as $body) {
            $response = $this->decide($sara, $body);
            $this->assertProblem($response, 400, 'VALIDATION_FAILED');
            $this->assertSame(['note'], array_keys($response->json('errors')));
        }
        $this->assertSame(VerificationStatus::Pending, RecruiterProfile::findOrFail($sara['user']['id'])->verification_status);

        $this->decide($sara, ['decision' => 'rejected', 'note' => 'Please add your LinkedIn profile.'])->assertOk()
            ->assertJson(['verificationStatus' => 'rejected', 'verificationNote' => 'Please add your LinkedIn profile.']);

        $this->api('GET', '/me/recruiter-profile', token: $sara['accessToken'])
            ->assertJson(['verificationStatus' => 'rejected', 'verificationNote' => 'Please add your LinkedIn profile.']);
    }

    public function test_an_approval_can_carry_a_note_but_does_not_need_one(): void
    {
        $sara = $this->pending('Sara Mostafa');

        $this->decide($sara, ['decision' => 'approved', 'note' => 'Verified by phone.'])->assertOk()
            ->assertJson(['verificationStatus' => 'approved', 'verificationNote' => 'Verified by phone.']);
    }

    public function test_rejecting_an_approved_recruiter_takes_them_out_of_search_and_stops_new_bookings(): void
    {
        $sara = $this->recruiter('Sara Mostafa');
        $offering = $this->offer($sara['accessToken']);
        $slot = $this->slot($sara['accessToken'], '+1 day 16:00', '+1 day 17:00');
        $candidate = $this->register()['accessToken'];
        $this->assertCount(1, $this->api('GET', '/recruiters', token: $candidate)->json('items'));

        $this->decide($sara, ['decision' => 'rejected', 'note' => 'Several complaints from candidates.'])->assertOk();

        $this->assertSame([], $this->api('GET', '/recruiters', token: $candidate)->json('items'));
        $this->assertProblem($this->api('GET', "/recruiters/{$sara['user']['id']}", token: $candidate), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('POST', '/sessions', ['offeringId' => $offering['id'], 'slotId' => $slot['id']], $candidate,
            ['Idempotency-Key' => '01a1c737-b065-7130-8d08-f913763e916c']), 404, 'NOT_FOUND');
    }

    public function test_a_rejected_recruiter_can_be_approved_later(): void
    {
        $sara = $this->recruiter('Sara Mostafa', status: VerificationStatus::Rejected);

        $this->decide($sara, ['decision' => 'approved'])->assertOk()->assertJson(['verificationStatus' => 'approved', 'verificationNote' => null]);
    }

    public function test_a_decision_must_be_approved_or_rejected(): void
    {
        $sara = $this->pending('Sara Mostafa');

        foreach ([[], ['decision' => 'pending'], ['decision' => 'maybe'], ['decision' => 'approved', 'note' => str_repeat('a', 1001)]] as $body) {
            $this->assertProblem($this->decide($sara, $body), 400, 'VALIDATION_FAILED');
        }
        $this->assertSame(VerificationStatus::Pending, RecruiterProfile::findOrFail($sara['user']['id'])->verification_status);
    }

    public function test_someone_without_a_recruiter_profile_cannot_be_verified(): void
    {
        $candidate = $this->register();
        $noProfile = $this->register(['role' => 'recruiter']);

        foreach ([$candidate, $noProfile, ['user' => ['id' => '01a1c737-b065-7130-8d08-f913763e916c']], ['user' => ['id' => 'not-a-uuid']]] as $who) {
            $this->assertProblem($this->decide($who, ['decision' => 'approved']), 404, 'NOT_FOUND');
        }
    }

    public function test_only_admins_can_use_the_admin_endpoints(): void
    {
        $sara = $this->pending('Sara Mostafa');
        $candidate = $this->register()['accessToken'];

        foreach ([$candidate, $sara['accessToken']] as $token) {
            $this->assertProblem($this->api('GET', '/admin/recruiters', token: $token), 403, 'FORBIDDEN_ROLE');
            // A recruiter cannot approve themselves.
            $this->assertProblem($this->decide($sara, ['decision' => 'approved'], $token), 403, 'FORBIDDEN_ROLE');
        }
        $this->assertProblem($this->api('GET', '/admin/recruiters'), 401, 'TOKEN_INVALID');
        $this->assertSame(VerificationStatus::Pending, RecruiterProfile::findOrFail($sara['user']['id'])->verification_status);
    }
}
