<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RefreshTokenRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TokenPair;
use App\Modules\Identity\Services\TokenService;
use App\Support\Http\ApiDate;
use App\Support\Http\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;

final class AuthController
{
    public function __construct(private readonly TokenService $tokens) {}

    /** POST /auth/register */
    public function register(RegisterRequest $request): JsonResponse
    {
        $emailTaken = ApiError::conflict('EMAIL_TAKEN', 'Email is already registered');
        $data = $request->validated();

        if (User::where('email', User::normalizeEmail($data['email']))->exists()) {
            throw $emailTaken;
        }

        try {
            // User and tokens are saved together or not at all.
            [$user, $pair] = DB::transaction(function () use ($data) {
                $user = User::create([
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => $data['role'],
                    'full_name' => $data['fullName'],
                    'phone' => $data['phone'] ?? null,
                    'locale' => $data['locale'] ?? 'ar',
                ]);

                return [$user, $this->tokens->issue($user)];
            });
        } catch (UniqueConstraintViolationException) {
            // Two registrations with the same email at the same moment both pass the check above.
            // The unique index decides which one wins.
            throw $emailTaken;
        }

        return $this->authResponse($user, $pair, 201);
    }

    /** POST /auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = User::normalizeEmail($request->validated('email'));
        $password = $request->validated('password');

        // Timebox makes every attempt take at least 200 ms (configurable, 0 in tests). Without it, "no such email" answers
        // faster than "wrong password" (no hash to check), which tells an attacker which emails exist.
        $user = (new Timebox)->call(function () use ($email, $password): ?User {
            $user = User::where('email', $email)->first();

            return $user !== null && Hash::check($password, $user->password) ? $user : null;
        }, config('careeredge.login_min_milliseconds') * 1000);

        // Same error for both cases, for the same reason.
        if ($user === null) {
            throw ApiError::unauthorized('INVALID_CREDENTIALS', 'Email or password is incorrect');
        }

        if (Hash::needsRehash($user->password)) {
            // The hash was made with older, weaker settings. Upgrade it now that we have the password.
            $user->update(['password' => $password]);
        }

        return $this->authResponse($user, $this->tokens->issue($user));
    }

    /** POST /auth/refresh */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        [$user, $pair] = $this->tokens->rotate($request->validated('refreshToken'));

        return $this->authResponse($user, $pair);
    }

    /** POST /auth/logout */
    public function logout(RefreshTokenRequest $request): Response
    {
        // Only the owner can revoke a token. An unknown token still answers 204, so logout is
        // safe to retry and says nothing about which tokens exist.
        $familyId = $this->tokens->familyOf($request->user(), $request->validated('refreshToken'));

        if ($familyId !== null) {
            $this->tokens->revokeFamily($familyId);
        }

        return response()->noContent();
    }

    /** The `AuthResponse` schema of docs/openapi.yaml. */
    private function authResponse(User $user, TokenPair $pair, int $status = 200): JsonResponse
    {
        return response()->json([
            'accessToken' => $pair->accessToken,
            'accessTokenExpiresAt' => ApiDate::format($pair->accessTokenExpiresAt),
            'refreshToken' => $pair->refreshToken,
            'refreshTokenExpiresAt' => ApiDate::format($pair->refreshTokenExpiresAt),
            'user' => new UserResource($user),
        ], $status);
    }
}
