<?php

use App\Http\Controllers\HealthController;
use App\Support\Http\ApiError;
use App\Support\Http\Middleware\SetLocaleFromHeader;
use App\Support\Http\ProblemRenderer;
use App\Support\Idempotency\EnsureIdempotent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Every API route lives under /api/v1, as the contract says.
        apiPrefix: 'api/v1',
        then: fn () => Route::get('/health', HealthController::class),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // There is no login page to send guests to. Without this, a request that has no token
        // and no "Accept: application/json" header makes Laravel look for a route named "login"
        // and crash with a 500 instead of answering 401.
        $middleware->redirectGuestsTo(fn () => null);

        // Every API request: choose Arabic or English from the Accept-Language header.
        $middleware->api(append: [SetLocaleFromHeader::class]);

        // `idempotent` on a route: a retried POST with the same Idempotency-Key runs only once.
        $middleware->alias(['idempotent' => EnsureIdempotent::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An ApiError is an expected answer to the client (wrong password, slot taken), not a bug to log.
        $exceptions->dontReport(ApiError::class);

        // This app only serves an API, so every error is rendered in the contract's format.
        $exceptions->render(fn (Throwable $e, Request $request) => ProblemRenderer::render($e, $request));
    })->create();
