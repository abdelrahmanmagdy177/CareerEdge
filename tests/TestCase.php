<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected const PASSWORD = 'Passw0rd!';

    /**
     * One API request, the way a real client sends it: JSON, and only the token given here.
     *
     * Two things differ from Laravel's plain $this->json() on purpose:
     * - Headers do not carry over from a previous request in the same test.
     * - The auth guards are reset first. Laravel keeps the app alive for a whole test, so
     *   without this a guard would remember the user of the previous request and a request
     *   with a bad token (or none) would still look logged in.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, array $data = [], ?string $token = null, array $headers = []): TestResponse
    {
        $this->asFreshClient($token);

        return $this->json($method, '/api/v1'.$uri, $data, $headers);
    }

    /** Forgets the previous request's login and headers, then sets the token for the next one. */
    protected function asFreshClient(?string $token = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this;
    }

    /**
     * Registers a new user through the API and returns the decoded AuthResponse.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function register(array $overrides = []): array
    {
        return $this->api('POST', '/auth/register', $this->registerBody($overrides))
            ->assertCreated()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registerBody(array $overrides = []): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => self::PASSWORD,
            'fullName' => 'Ahmed Hassan',
            'role' => 'candidate',
            ...$overrides,
        ];
    }

    /** Asserts a contract-shaped error: right status, problem+json, and the expected code. */
    protected function assertProblem(TestResponse $response, int $status, string $code): TestResponse
    {
        $response->assertStatus($status);
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $response->assertJson(['status' => $status, 'code' => $code]);
        $this->assertNotEmpty($response->json('title'));

        return $response;
    }
}
