<?php

namespace Tests\Feature\Profiles;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Models\RecruiterProfile;
use App\Modules\Profiles\Models\Specialization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecruiterProfileTest extends TestCase
{
    use RefreshDatabase;

    private const BIO = 'Technical recruiter for 8 years, hiring backend and mobile engineers for fintech companies.';

    private Specialization $backend;

    private Specialization $mobile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backend = Specialization::factory()->create(['slug' => 'backend', 'name_en' => 'Backend Development']);
        $this->mobile = Specialization::factory()->create(['slug' => 'mobile', 'name_en' => 'Mobile Development']);
    }

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return [
            'bio' => self::BIO,
            'yearsOfExperience' => 8,
            'currentTitle' => 'Senior Technical Recruiter',
            'linkedinUrl' => 'https://www.linkedin.com/in/sara-mostafa',
            'specializationIds' => [$this->mobile->id, $this->backend->id],
            'languages' => ['ar', 'en'],
            'timezone' => 'Africa/Cairo',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function registerRecruiter(): array
    {
        return $this->register(['role' => 'recruiter', 'fullName' => 'Sara Mostafa']);
    }

    private function setStatus(string $userId, VerificationStatus $status, ?string $note = null): void
    {
        // What an admin will do through the API in milestone M7.
        RecruiterProfile::whereKey($userId)->update(['verification_status' => $status, 'verification_note' => $note]);
    }

    public function test_a_recruiter_creates_a_profile_that_waits_for_approval(): void
    {
        $auth = $this->registerRecruiter();

        $response = $this->api('PUT', '/me/recruiter-profile', $this->body(), $auth['accessToken'])->assertOk();

        $response->assertExactJson([
            'userId' => $auth['user']['id'],
            'fullName' => 'Sara Mostafa',
            'avatarUrl' => null,
            'bio' => self::BIO,
            'yearsOfExperience' => 8,
            'currentTitle' => 'Senior Technical Recruiter',
            'linkedinUrl' => 'https://www.linkedin.com/in/sara-mostafa',
            // Sorted by slug, whatever order they were sent in.
            'specializations' => [
                ['id' => $this->backend->id, 'slug' => 'backend', 'name' => 'Backend Development', 'parentId' => null],
                ['id' => $this->mobile->id, 'slug' => 'mobile', 'name' => 'Mobile Development', 'parentId' => null],
            ],
            'languages' => ['ar', 'en'],
            'timezone' => 'Africa/Cairo',
            'verificationStatus' => 'pending',
            'verificationNote' => null,
            'ratingAvg' => 0,
            'ratingCount' => 0,
            'cancellationCount' => 0,
        ]);

        $read = $this->api('GET', '/me/recruiter-profile', token: $auth['accessToken'])->assertOk();
        $this->assertEquals($response->json(), $read->json());
        $this->api('GET', '/me', token: $auth['accessToken'])->assertJson(['hasProfile' => true]);
    }

    public function test_optional_fields_can_be_left_out_and_languages_default_to_arabic(): void
    {
        $token = $this->registerRecruiter()['accessToken'];

        $this->api('PUT', '/me/recruiter-profile', [
            'bio' => self::BIO,
            'yearsOfExperience' => 1,
            'specializationIds' => [$this->backend->id],
            'timezone' => 'Africa/Cairo',
        ], $token)->assertOk()->assertJson([
            'currentTitle' => null,
            'linkedinUrl' => null,
            'languages' => ['ar'],
        ]);
    }

    public function test_reading_before_creating_returns_404(): void
    {
        $token = $this->registerRecruiter()['accessToken'];

        $this->assertProblem($this->api('GET', '/me/recruiter-profile', token: $token), 404, 'NOT_FOUND');
    }

    public function test_a_recruiter_cannot_approve_or_rate_themselves_through_the_request(): void
    {
        $token = $this->registerRecruiter()['accessToken'];

        $this->api('PUT', '/me/recruiter-profile', $this->body([
            'verificationStatus' => 'approved',
            'verification_status' => 'approved',
            'ratingAvg' => 5,
            'rating_avg' => 5,
            'ratingCount' => 100,
            'cancellationCount' => -3,
        ]), $token)->assertOk()->assertJson([
            'verificationStatus' => 'pending',
            'ratingAvg' => 0,
            'ratingCount' => 0,
            'cancellationCount' => 0,
        ]);
    }

    public function test_an_approved_recruiter_stays_approved_after_editing(): void
    {
        $auth = $this->registerRecruiter();
        $this->api('PUT', '/me/recruiter-profile', $this->body(), $auth['accessToken'])->assertOk();
        $this->setStatus($auth['user']['id'], VerificationStatus::Approved);

        $this->api('PUT', '/me/recruiter-profile', $this->body(['yearsOfExperience' => 9]), $auth['accessToken'])
            ->assertOk()
            ->assertJson(['verificationStatus' => 'approved', 'yearsOfExperience' => 9]);
    }

    public function test_a_rejected_recruiter_goes_back_to_pending_when_they_resubmit(): void
    {
        $auth = $this->registerRecruiter();
        $this->api('PUT', '/me/recruiter-profile', $this->body(), $auth['accessToken'])->assertOk();
        $this->setStatus($auth['user']['id'], VerificationStatus::Rejected, 'Please add your LinkedIn profile.');

        $this->api('GET', '/me/recruiter-profile', token: $auth['accessToken'])
            ->assertJson(['verificationStatus' => 'rejected', 'verificationNote' => 'Please add your LinkedIn profile.']);

        $this->api('PUT', '/me/recruiter-profile', $this->body(), $auth['accessToken'])
            ->assertOk()
            ->assertJson(['verificationStatus' => 'pending', 'verificationNote' => null]);
    }

    public function test_put_replaces_the_list_of_specializations(): void
    {
        $token = $this->registerRecruiter()['accessToken'];
        $this->api('PUT', '/me/recruiter-profile', $this->body(), $token)->assertOk();

        $response = $this->api('PUT', '/me/recruiter-profile', $this->body(['specializationIds' => [$this->mobile->id]]), $token);

        $this->assertSame(['mobile'], array_column($response->json('specializations'), 'slug'));
        $this->assertDatabaseCount('recruiter_specializations', 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_input_is_rejected_with_the_field_name(array $overrides, string $field): void
    {
        $token = $this->registerRecruiter()['accessToken'];

        $response = $this->api('PUT', '/me/recruiter-profile', $this->body($overrides), $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
        $this->assertCount(1, $response->json("errors.$field"));
        $this->assertDatabaseCount('recruiter_profiles', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): array
    {
        $unknown = '01a1c737-b065-7130-8d08-f913763e916c';

        return [
            'bio under 50 characters' => [['bio' => 'Too short.'], 'bio'],
            'bio over 2000 characters' => [['bio' => str_repeat('a', 2001)], 'bio'],
            'zero years' => [['yearsOfExperience' => 0], 'yearsOfExperience'],
            'title over 100 characters' => [['currentTitle' => str_repeat('a', 101)], 'currentTitle'],
            'linkedin that is not a url' => [['linkedinUrl' => 'sara-mostafa'], 'linkedinUrl'],
            'linkedin with a script scheme' => [['linkedinUrl' => 'javascript:alert(1)'], 'linkedinUrl'],
            'no specializations' => [['specializationIds' => []], 'specializationIds'],
            'specialization that does not exist' => [['specializationIds' => [$unknown]], 'specializationIds'],
            'specialization id that is not a uuid' => [['specializationIds' => ['backend']], 'specializationIds'],
            'six specializations' => [['specializationIds' => array_fill(0, 6, $unknown)], 'specializationIds'],
            'unknown language' => [['languages' => ['ar', 'fr']], 'languages'],
            'empty language list' => [['languages' => []], 'languages'],
            'timezone that is not an IANA name' => [['timezone' => 'Cairo time'], 'timezone'],
            'missing timezone' => [['timezone' => null], 'timezone'],
        ];
    }

    public function test_the_same_specialization_twice_is_rejected(): void
    {
        $token = $this->registerRecruiter()['accessToken'];

        $response = $this->api('PUT', '/me/recruiter-profile', $this->body([
            'specializationIds' => [$this->backend->id, $this->backend->id],
        ]), $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['specializationIds'], array_keys($response->json('errors')));
    }

    public function test_a_candidate_cannot_use_the_recruiter_profile_endpoints(): void
    {
        $candidate = $this->register()['accessToken'];

        $this->assertProblem($this->api('GET', '/me/recruiter-profile', token: $candidate), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('PUT', '/me/recruiter-profile', $this->body(), $candidate), 403, 'FORBIDDEN_ROLE');
        $this->assertDatabaseCount('recruiter_profiles', 0);
    }

    public function test_the_endpoints_need_a_token(): void
    {
        $this->assertProblem($this->api('GET', '/me/recruiter-profile'), 401, 'TOKEN_INVALID');
        $this->assertProblem($this->api('PUT', '/me/recruiter-profile', $this->body()), 401, 'TOKEN_INVALID');
    }
}
