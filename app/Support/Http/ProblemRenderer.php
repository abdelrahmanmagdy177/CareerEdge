<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns every exception into the error format of the contract:
 * application/problem+json with a stable `code`, and `errors` for validation failures.
 *
 * Registered once in bootstrap/app.php, so no controller builds error responses by hand.
 */
final class ProblemRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiError => self::problem($e->status, $e->errorCode, $e->title, $e->fieldErrors),

            // Laravel's default for a failed validation is 422. The contract says 400.
            $e instanceof ValidationException => self::problem(400, 'VALIDATION_FAILED', 'Validation failed', $e->errors()),

            $e instanceof AuthenticationException => self::isExpiredToken($request)
                ? self::problem(401, 'TOKEN_EXPIRED', 'Access token expired')
                : self::problem(401, 'TOKEN_INVALID', 'Access token is missing or invalid'),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => self::problem(403, 'FORBIDDEN_ROLE', 'This action needs another role'),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::problem(404, 'NOT_FOUND', 'Not found'),

            // Keeps the Retry-After header that the throttle middleware put on the exception.
            $e instanceof TooManyRequestsHttpException => self::problem(429, 'RATE_LIMITED', 'Too many requests', headers: $e->getHeaders()),

            $e instanceof HttpExceptionInterface => self::problem($e->getStatusCode(), 'HTTP_ERROR', 'Request could not be handled', headers: $e->getHeaders()),

            // Never send exception details to the client outside debug mode. They can leak internals.
            default => self::problem(500, 'INTERNAL_ERROR', 'Something went wrong', detail: config('app.debug') ? $e->getMessage() : null),
        };
    }

    /**
     * @param  array<string, list<string>>|null  $fieldErrors
     * @param  array<string, mixed>  $headers
     */
    private static function problem(
        int $status,
        string $code,
        string $title,
        ?array $fieldErrors = null,
        ?string $detail = null,
        array $headers = [],
    ): JsonResponse {
        $body = [
            'type' => 'https://careeredge.dev/errors/'.Str::of($code)->lower()->replace('_', '-'),
            'title' => $title,
            'status' => $status,
            'code' => $code,
        ];

        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        if ($fieldErrors !== null) {
            $body['errors'] = $fieldErrors;
        }

        return response()->json($body, $status, [...$headers, 'Content-Type' => 'application/problem+json']);
    }

    /**
     * Sanctum treats an expired token like a missing one. The app needs to tell them apart:
     * expired means "refresh and retry", invalid means "log in again".
     */
    private static function isExpiredToken(Request $request): bool
    {
        $bearer = $request->bearerToken();
        if ($bearer === null) {
            return false;
        }

        return PersonalAccessToken::findToken($bearer)?->expires_at?->isPast() ?? false;
    }
}
