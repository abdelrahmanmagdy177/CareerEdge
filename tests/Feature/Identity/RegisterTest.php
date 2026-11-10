<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_201_with_tokens_and_the_new_user(): void
    {
        $this->freezeSecond();

        $response = $this->api('POST', '/auth/register', $this->registerBody([
            'email' => 'sara@example.com',
            'role' => 'recruiter',
        ]));

        $response->assertCreated()->assertJson([
            'accessTokenExpiresAt' => now()->addMinutes(15)->utc()->format('Y-m-d\TH:i:s\Z'),
            'refreshTokenExpiresAt' => now()->addDays(30)->utc()->format('Y-m-d\TH:i:s\Z'),
            'user' => [
                'email' => 'sara@example.com',
                'fullName' => 'Ahmed Hassan',
                'role' => 'recruiter',
                'phone' => null,
                'locale' => 'ar',
                'hasProfile' => false,
                'createdAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);

        $this->assertStringStartsWith('rt_', $response->json('refreshToken'));
        $this->assertNotEmpty($response->json('accessToken'));
    }

    public function test_register_response_matches_the_contract_shape_exactly(): void
    {
        $json = $this->register();

        // No extra keys such as the password hash or snake_case columns.
        $this->assertSame(
            ['accessToken', 'accessTokenExpiresAt', 'refreshToken', 'refreshTokenExpiresAt', 'user'],
            array_keys($json),
        );
        $this->assertSame(
            ['id', 'email', 'fullName', 'role', 'phone', 'locale', 'hasProfile', 'createdAt'],
            array_keys($json['user']),
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['user']['createdAt']);
    }

    public function test_the_password_is_stored_hashed_and_the_refresh_token_is_not_stored_at_all(): void
    {
        $json = $this->register(['email' => 'ahmed@example.com']);

        $this->assertDatabaseMissing('users', ['password' => self::PASSWORD]);
        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $json['refreshToken']]);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => hash('sha256', $json['refreshToken'])]);
    }

    public function test_register_with_an_email_that_exists_in_another_case_returns_409(): void
    {
        $this->register(['email' => 'ahmed@example.com']);

        $response = $this->api('POST', '/auth/register', $this->registerBody(['email' => 'AHMED@Example.com']));

        $this->assertProblem($response, 409, 'EMAIL_TAKEN');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_register_with_invalid_fields_returns_400_with_one_message_per_field(): void
    {
        $response = $this->api('POST', '/auth/register', [
            'email' => 'not-an-email',
            'password' => 'lettersonly',
            'fullName' => 'A',
            'role' => 'candidate',
            'phone' => '01001234567',
            'locale' => 'fr',
        ]);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');

        $errors = $response->json('errors');
        $this->assertEqualsCanonicalizing(['email', 'password', 'fullName', 'phone', 'locale'], array_keys($errors));
        foreach ($errors as $messages) {
            $this->assertCount(1, $messages);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_with_an_empty_body_returns_400_instead_of_crashing(): void
    {
        $response = $this->api('POST', '/auth/register');

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['Email is required.'], $response->json('errors.email'));
    }

    public function test_malformed_json_returns_400_in_the_contract_error_format(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/auth/register',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{ this is not json',
        );

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
    }

    public function test_register_cannot_create_an_admin(): void
    {
        $response = $this->api('POST', '/auth/register', $this->registerBody(['role' => 'admin']));

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertArrayHasKey('role', $response->json('errors'));
    }

    #[DataProvider('weakPasswords')]
    public function test_weak_passwords_are_rejected(string $password): void
    {
        $response = $this->api('POST', '/auth/register', $this->registerBody(['password' => $password]));

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['password'], array_keys($response->json('errors')));
    }

    /** @return array<string, array{string}> */
    public static function weakPasswords(): array
    {
        return [
            'under 8 characters' => ['short1'],
            'no digit' => ['onlyletters'],
            'no letter' => ['1234567890'],
            'over 128 characters' => ['a1'.str_repeat('x', 127)],
        ];
    }

    public function test_a_128_character_password_is_accepted(): void
    {
        $this->register(['password' => 'a1'.str_repeat('x', 126)]);

        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('badPhones')]
    public function test_phones_that_are_not_international_format_are_rejected(string $phone): void
    {
        $response = $this->api('POST', '/auth/register', $this->registerBody(['phone' => $phone]));

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['phone'], array_keys($response->json('errors')));
    }

    /** @return array<string, array{string}> */
    public static function badPhones(): array
    {
        return [
            'no country code' => ['01001234567'],
            'country code starts with 0' => ['+0201001234567'],
            'too short' => ['+2010'],
            'has spaces' => ['+20 100 123 4567'],
        ];
    }

    public function test_a_full_name_of_one_character_after_trimming_is_rejected(): void
    {
        $response = $this->api('POST', '/auth/register', $this->registerBody(['fullName' => '   A   ']));

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['fullName'], array_keys($response->json('errors')));
    }
}
