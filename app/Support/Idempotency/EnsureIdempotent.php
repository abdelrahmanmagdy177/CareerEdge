<?php

namespace App\Support\Idempotency;

use App\Support\Http\ApiError;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a POST safe to send twice.
 *
 * A phone on a bad connection may send "book this slot" or "pay" and never get the answer.
 * It retries with the same `Idempotency-Key` header. The first time, the request runs and
 * its successful answer is saved. Every later request with that key gets the saved answer
 * back and nothing runs again, so the user is never booked or charged twice.
 *
 * Use on routes as `idempotent`, after `auth:sanctum` (keys are per user).
 */
final class EnsureIdempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Send an Idempotency-Key header containing a UUID.'],
            ]);
        }

        $userId = $request->user()->id;
        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        try {
            // The lock covers the case where the retry arrives while the first request is
            // still running: the retry waits here, then finds the saved answer.
            return Cache::lock("idempotency:$userId:$key", 30)->block(10, fn () => $this->run($request, $next, $userId, $key, $fingerprint));
        } catch (LockTimeoutException) {
            throw ApiError::conflict('IDEMPOTENCY_IN_PROGRESS', 'The first request with this key is still running. Try again shortly');
        }
    }

    private function run(Request $request, Closure $next, string $userId, string $key, string $fingerprint): Response
    {
        $saved = IdempotencyKey::where('user_id', $userId)->where('key', $key)->first();

        if ($saved !== null && $saved->isExpired()) {
            $saved->delete();
            $saved = null;
        }

        if ($saved !== null) {
            // Same key, different request: a client bug. Answering with the old response
            // would be wrong, and running the new request would break the promise of the key.
            if (! hash_equals($saved->fingerprint, $fingerprint)) {
                throw new ApiError(422, 'IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used for a different request');
            }

            return response()
                ->json(json_decode($saved->response_body, true), $saved->response_status)
                ->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        // Only successes are saved. A failed request changed nothing, so running it again
        // on retry is safe, and it may well succeed the second time.
        if ($response->isSuccessful()) {
            IdempotencyKey::create([
                'user_id' => $userId,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'response_status' => $response->getStatusCode(),
                'response_body' => (string) $response->getContent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
