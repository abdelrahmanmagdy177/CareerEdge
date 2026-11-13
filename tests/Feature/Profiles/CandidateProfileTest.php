<?php

namespace Tests\Feature\Profiles;

use App\Modules\Profiles\Models\Specialization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CandidateProfileTest extends TestCase
{
    use RefreshDatabase;

    private Specialization $backend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backend = Specialization::factory()->create([
            'slug' => 'backend', 'name_en' => 'Backend Development', 'name_ar' => 'تطوير الواجهة الخلفية',
        ]);
    }

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return [
            'headline' => 'Backend developer, 3 years with Laravel',
            'specializationId' => $this->backend->id,
            'targetRole' => 'Senior Backend Engineer',
            'targetLevel' => 'senior',
            'yearsOfExperience' => 3,
            ...$overrides,
        ];
    }

    public function test_a_candidate_creates_a_profile_and_reads_it_back(): void
    {
        $auth = $this->register();

        $created = $this->api('PUT', '/me/candidate-profile', $this->body(), $auth['accessToken'])->assertOk();

        $created->assertExactJson([
            'userId' => $auth['user']['id'],
            'headline' => 'Backend developer, 3 years with Laravel',
            'specializationId' => $this->backend->id,
            'specialization' => [
                'id' => $this->backend->id, 'slug' => 'backend', 'name' => 'Backend Development', 'parentId' => null,
            ],
            'targetRole' => 'Senior Backend Engineer',
            'targetLevel' => 'senior',
            'yearsOfExperience' => 3,
        ]);

        $read = $this->api('GET', '/me/candidate-profile', token: $auth['accessToken'])->assertOk();
        $this->assertSame($created->json(), $read->json());
    }

    public function test_has_profile_turns_true_once_the_profile_exists(): void
    {
        $auth = $this->register();
        $this->assertFalse($auth['user']['hasProfile']);

        $this->api('PUT', '/me/candidate-profile', $this->body(), $auth['accessToken'])->assertOk();

        $this->api('GET', '/me', token: $auth['accessToken'])->assertJson(['hasProfile' => true]);
    }

    public function test_reading_before_creating_returns_404(): void
    {
        $auth = $this->register();

        $this->assertProblem($this->api('GET', '/me/candidate-profile', token: $auth['accessToken']), 404, 'NOT_FOUND');
    }

    public function test_put_replaces_the_whole_profile_so_omitted_optional_fields_become_null(): void
    {
        $token = $this->register()['accessToken'];
        $this->api('PUT', '/me/candidate-profile', $this->body(), $token)->assertOk();

        $this->api('PUT', '/me/candidate-profile', [
            'specializationId' => $this->backend->id,
            'targetRole' => 'Tech Lead',
            'yearsOfExperience' => 0,
        ], $token)->assertOk()->assertJson([
            'headline' => null,
            'targetLevel' => null,
            'targetRole' => 'Tech Lead',
            'yearsOfExperience' => 0,
        ]);

        $this->assertDatabaseCount('candidate_profiles', 1);
    }

    public function test_the_specialization_name_follows_accept_language(): void
    {
        $token = $this->register()['accessToken'];
        $this->api('PUT', '/me/candidate-profile', $this->body(), $token)->assertOk();

        $this->api('GET', '/me/candidate-profile', token: $token, headers: ['Accept-Language' => 'ar'])
            ->assertJsonPath('specialization.name', 'تطوير الواجهة الخلفية');
    }

    public function test_one_candidate_cannot_see_or_change_another_candidates_profile(): void
    {
        $ahmed = $this->register()['accessToken'];
        $omar = $this->register()['accessToken'];
        $this->api('PUT', '/me/candidate-profile', $this->body(['targetRole' => 'Ahmed role']), $ahmed)->assertOk();

        // The endpoints only ever act on "me", so Omar simply has no profile yet.
        $this->assertProblem($this->api('GET', '/me/candidate-profile', token: $omar), 404, 'NOT_FOUND');
        $this->api('PUT', '/me/candidate-profile', $this->body(['targetRole' => 'Omar role']), $omar)->assertOk();

        $this->api('GET', '/me/candidate-profile', token: $ahmed)->assertJson(['targetRole' => 'Ahmed role']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_input_is_rejected_with_the_field_name(array $overrides, string $field): void
    {
        $token = $this->register()['accessToken'];

        $response = $this->api('PUT', '/me/candidate-profile', $this->body($overrides), $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
        $this->assertDatabaseCount('candidate_profiles', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): array
    {
        return [
            'specialization that does not exist' => [['specializationId' => '01a1c737-b065-7130-8d08-f913763e916c'], 'specializationId'],
            'specialization id that is not a uuid' => [['specializationId' => 'backend'], 'specializationId'],
            'missing specialization' => [['specializationId' => null], 'specializationId'],
            'missing target role' => [['targetRole' => ''], 'targetRole'],
            'target role over 100 characters' => [['targetRole' => str_repeat('a', 101)], 'targetRole'],
            'headline over 150 characters' => [['headline' => str_repeat('a', 151)], 'headline'],
            'unknown level' => [['targetLevel' => 'expert'], 'targetLevel'],
            'more than 50 years' => [['yearsOfExperience' => 51], 'yearsOfExperience'],
            'negative years' => [['yearsOfExperience' => -1], 'yearsOfExperience'],
            'years with a fraction' => [['yearsOfExperience' => 2.5], 'yearsOfExperience'],
        ];
    }

    public function test_a_recruiter_cannot_use_the_candidate_profile_endpoints(): void
    {
        $recruiter = $this->register(['role' => 'recruiter'])['accessToken'];

        $this->assertProblem($this->api('GET', '/me/candidate-profile', token: $recruiter), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('PUT', '/me/candidate-profile', $this->body(), $recruiter), 403, 'FORBIDDEN_ROLE');
        $this->assertDatabaseCount('candidate_profiles', 0);
    }

    public function test_the_endpoints_need_a_token(): void
    {
        $this->assertProblem($this->api('GET', '/me/candidate-profile'), 401, 'TOKEN_INVALID');
        $this->assertProblem($this->api('PUT', '/me/candidate-profile', $this->body()), 401, 'TOKEN_INVALID');
    }
}
