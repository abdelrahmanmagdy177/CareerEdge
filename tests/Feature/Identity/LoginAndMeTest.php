<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginAndMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_correct_credentials_returns_a_working_access_token(): void
    {
        $registered = $this->register(['email' => 'ahmed@example.com']);

        // Email is matched case-insensitively.
        $login = $this->api('POST', '/auth/login', ['email' => 'AHMED@example.com', 'password' => self::PASSWORD]);

        $login->assertOk();
        $me = $this->api('GET', '/me', token: $login->json('accessToken'))->assertOk();

        // The user in the login response and the user read back from the database are identical.
        $this->assertSame($login->json('user'), $me->json());
        $this->assertSame($registered['user'], $me->json());
    }

    public function test_login_fails_the_same_way_for_a_wrong_password_and_an_unknown_email(): void
    {
        $this->register(['email' => 'ahmed@example.com']);

        $wrongPassword = $this->api('POST', '/auth/login', ['email' => 'ahmed@example.com', 'password' => 'Wrong-passw0rd']);
        $unknownEmail = $this->api('POST', '/auth/login', ['email' => 'nobody@example.com', 'password' => self::PASSWORD]);

        $this->assertProblem($wrongPassword, 401, 'INVALID_CREDENTIALS');
        $this->assertProblem($unknownEmail, 401, 'INVALID_CREDENTIALS');
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    public function test_login_without_fields_returns_400(): void
    {
        $response = $this->api('POST', '/auth/login');

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertEqualsCanonicalizing(['email', 'password'], array_keys($response->json('errors')));
    }

    public function test_me_without_a_token_returns_401_token_invalid(): void
    {
        $this->assertProblem($this->api('GET', '/me'), 401, 'TOKEN_INVALID');
    }

    public function test_a_client_that_sends_no_accept_header_still_gets_401_not_a_redirect_or_500(): void
    {
        // Plain call(): no "Accept: application/json", like curl or a browser.
        $response = $this->call('GET', '/api/v1/me');

        $this->assertProblem($response, 401, 'TOKEN_INVALID');
    }

    public function test_me_with_a_tampered_token_returns_401_token_invalid(): void
    {
        $auth = $this->register();

        $this->assertProblem($this->api('GET', '/me', token: $auth['accessToken'].'x'), 401, 'TOKEN_INVALID');
    }

    public function test_a_request_without_a_token_is_rejected_even_right_after_an_authenticated_one(): void
    {
        $auth = $this->register();
        $this->api('GET', '/me', token: $auth['accessToken'])->assertOk();

        $this->assertProblem($this->api('GET', '/me'), 401, 'TOKEN_INVALID');
    }

    public function test_access_token_stops_working_after_15_minutes(): void
    {
        $auth = $this->register();

        $this->travel(14)->minutes();
        $this->api('GET', '/me', token: $auth['accessToken'])->assertOk();

        $this->travel(2)->minutes();
        $this->assertProblem($this->api('GET', '/me', token: $auth['accessToken']), 401, 'TOKEN_EXPIRED');
    }

    public function test_patch_me_changes_only_the_fields_that_were_sent(): void
    {
        $token = $this->register()['accessToken'];

        $this->api('PATCH', '/me', ['phone' => '+201001234567', 'locale' => 'en'], $token)
            ->assertOk()
            ->assertJson(['phone' => '+201001234567', 'locale' => 'en', 'fullName' => 'Ahmed Hassan']);

        // Renaming must not touch the phone that was set before.
        $this->api('PATCH', '/me', ['fullName' => 'Ahmed H.'], $token)
            ->assertOk()
            ->assertJson(['fullName' => 'Ahmed H.', 'phone' => '+201001234567']);

        // An explicit null clears it.
        $this->api('PATCH', '/me', ['phone' => null], $token)
            ->assertOk()
            ->assertJson(['phone' => null, 'fullName' => 'Ahmed H.', 'locale' => 'en']);
    }

    public function test_patch_me_rejects_an_empty_body(): void
    {
        $token = $this->register()['accessToken'];

        $response = $this->api('PATCH', '/me', [], $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertArrayHasKey('body', $response->json('errors'));
    }

    public function test_patch_me_saves_nothing_when_one_field_is_invalid(): void
    {
        $token = $this->register()['accessToken'];

        $response = $this->api('PATCH', '/me', ['fullName' => 'New Name', 'phone' => '123'], $token);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['phone'], array_keys($response->json('errors')));
        $this->api('GET', '/me', token: $token)->assertJson(['fullName' => 'Ahmed Hassan', 'phone' => null]);
    }

    public function test_patch_me_cannot_change_email_or_role(): void
    {
        $auth = $this->register(['email' => 'ahmed@example.com']);

        $this->api('PATCH', '/me', ['fullName' => 'Ahmed H.', 'email' => 'other@example.com', 'role' => 'admin'], $auth['accessToken'])
            ->assertOk()
            ->assertJson(['email' => 'ahmed@example.com', 'role' => 'candidate']);
    }

    public function test_patch_me_without_a_token_returns_401(): void
    {
        $this->assertProblem($this->api('PATCH', '/me', ['fullName' => 'Someone']), 401, 'TOKEN_INVALID');
    }
}
