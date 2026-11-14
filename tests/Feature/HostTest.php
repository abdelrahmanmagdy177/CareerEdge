<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class HostTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_healthy_when_the_database_is_reachable(): void
    {
        $this->get('/health')->assertOk()->assertSee('Healthy');
    }

    public function test_the_app_starts_in_production_even_though_only_the_fake_payment_gateway_exists(): void
    {
        // What `composer install` does on a server or on CI before any .env exists: it boots
        // the app in production mode. The fake gateway must refuse payments there, not
        // refuse to let the whole app start.
        $result = Process::path(base_path())->env(['APP_ENV' => 'production'])->run([PHP_BINARY, 'artisan', 'package:discover']);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    public function test_an_unknown_route_returns_404_in_the_contract_error_format(): void
    {
        $this->assertProblem($this->api('GET', '/no-such-endpoint'), 404, 'NOT_FOUND');
    }

    public function test_auth_endpoints_return_429_with_retry_after_once_the_limit_is_reached(): void
    {
        config(['careeredge.auth_rate_limit_per_minute' => 3]);
        $body = ['email' => 'nobody@example.com', 'password' => self::PASSWORD];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->api('POST', '/auth/login', $body)->assertUnauthorized();
        }

        $blocked = $this->api('POST', '/auth/login', $body);

        $this->assertProblem($blocked, 429, 'RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
    }

    public function test_an_unexpected_error_returns_500_without_leaking_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/boom', fn () => throw new RuntimeException('secret internal detail'));

        $response = $this->api('GET', '/boom');

        $this->assertProblem($response, 500, 'INTERNAL_ERROR');
        $this->assertArrayNotHasKey('detail', $response->json());
        $this->assertStringNotContainsString('secret', $response->getContent());
    }
}
