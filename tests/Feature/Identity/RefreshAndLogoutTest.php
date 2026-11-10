<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RefreshAndLogoutTest extends TestCase
{
    use RefreshDatabase;

    private function refresh(string $refreshToken): TestResponse
    {
        return $this->api('POST', '/auth/refresh', ['refreshToken' => $refreshToken]);
    }

    public function test_refresh_returns_a_new_pair_that_works(): void
    {
        $auth = $this->register();

        $refreshed = $this->refresh($auth['refreshToken'])->assertOk();

        $this->assertNotSame($auth['refreshToken'], $refreshed->json('refreshToken'));
        $this->assertNotSame($auth['accessToken'], $refreshed->json('accessToken'));
        $this->assertSame($auth['user'], $refreshed->json('user'));
        $this->api('GET', '/me', token: $refreshed->json('accessToken'))->assertOk();
    }

    public function test_the_old_access_token_stops_working_once_the_pair_was_refreshed(): void
    {
        $auth = $this->register();

        $this->refresh($auth['refreshToken'])->assertOk();

        $this->assertProblem($this->api('GET', '/me', token: $auth['accessToken']), 401, 'TOKEN_INVALID');
    }

    public function test_reusing_a_refresh_token_revokes_every_token_from_that_login(): void
    {
        $auth = $this->register();
        $second = $this->refresh($auth['refreshToken'])->assertOk()->json();

        // The first token was already exchanged. Presenting it again looks like theft.
        $this->assertProblem($this->refresh($auth['refreshToken']), 401, 'TOKEN_INVALID');

        // So the newest tokens of the same login must be dead too.
        $this->assertProblem($this->refresh($second['refreshToken']), 401, 'TOKEN_INVALID');
        $this->assertProblem($this->api('GET', '/me', token: $second['accessToken']), 401, 'TOKEN_INVALID');
    }

    public function test_reuse_in_one_login_does_not_affect_another_login_of_the_same_user(): void
    {
        $phone = $this->register(['email' => 'ahmed@example.com']);
        $laptop = $this->api('POST', '/auth/login', ['email' => 'ahmed@example.com', 'password' => self::PASSWORD])->json();

        $this->refresh($phone['refreshToken'])->assertOk();
        $this->assertProblem($this->refresh($phone['refreshToken']), 401, 'TOKEN_INVALID');

        $this->api('GET', '/me', token: $laptop['accessToken'])->assertOk();
        $this->refresh($laptop['refreshToken'])->assertOk();
    }

    public function test_refresh_with_an_unknown_token_returns_401(): void
    {
        $this->assertProblem($this->refresh('rt_this-token-was-never-issued'), 401, 'TOKEN_INVALID');
    }

    public function test_refresh_without_a_token_returns_400(): void
    {
        $this->assertProblem($this->api('POST', '/auth/refresh'), 400, 'VALIDATION_FAILED');
    }

    public function test_refresh_token_expires_after_30_days(): void
    {
        $auth = $this->register();

        $this->travel(29)->days();
        $refreshed = $this->refresh($auth['refreshToken'])->assertOk()->json();

        $this->travel(30)->days();
        $this->travel(1)->seconds();
        $this->assertProblem($this->refresh($refreshed['refreshToken']), 401, 'TOKEN_EXPIRED');
    }

    public function test_logout_kills_both_tokens_at_once_and_can_be_repeated(): void
    {
        $auth = $this->register();
        $body = ['refreshToken' => $auth['refreshToken']];

        $this->api('POST', '/auth/logout', $body, $auth['accessToken'])->assertNoContent();

        $this->assertProblem($this->refresh($auth['refreshToken']), 401, 'TOKEN_INVALID');
        $this->assertProblem($this->api('GET', '/me', token: $auth['accessToken']), 401, 'TOKEN_INVALID');
    }

    public function test_logout_with_an_unknown_refresh_token_still_returns_204(): void
    {
        $auth = $this->register();

        $this->api('POST', '/auth/logout', ['refreshToken' => 'rt_unknown'], $auth['accessToken'])->assertNoContent();

        $this->api('GET', '/me', token: $auth['accessToken'])->assertOk();
    }

    public function test_logout_cannot_revoke_another_users_refresh_token(): void
    {
        $victim = $this->register();
        $attacker = $this->register();

        $this->api('POST', '/auth/logout', ['refreshToken' => $victim['refreshToken']], $attacker['accessToken'])
            ->assertNoContent();

        $this->refresh($victim['refreshToken'])->assertOk();
    }

    public function test_logout_without_an_access_token_returns_401(): void
    {
        $response = $this->api('POST', '/auth/logout', ['refreshToken' => 'rt_anything']);

        $this->assertProblem($response, 401, 'TOKEN_INVALID');
    }
}
