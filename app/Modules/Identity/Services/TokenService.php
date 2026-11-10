<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\RefreshToken;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiError;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues and rotates the token pair.
 *
 * - Access token: a Sanctum token that lives 15 minutes.
 * - Refresh token: ours. Random, stored only as a hash, valid 30 days, usable once.
 *
 * Every refresh burns the old refresh token and gives a new one in the same "family".
 * If a burned token shows up again, someone copied it, so the whole family is revoked.
 */
final class TokenService
{
    /**
     * Creates a new pair. Leave $familyId null on register and login to start a new family.
     */
    public function issue(User $user, ?string $familyId = null): TokenPair
    {
        $familyId ??= (string) Str::uuid7();
        $accessExpiresAt = now()->addMinutes(config('careeredge.access_token_minutes'));
        $refreshExpiresAt = now()->addDays(config('careeredge.refresh_token_days'));

        // The token name carries the family id. That is how revokeFamily() finds the access tokens.
        $accessToken = $user->createToken($familyId, ['*'], $accessExpiresAt)->plainTextToken;

        $refreshToken = 'rt_'.Str::random(48);
        RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId,
            'token_hash' => self::hash($refreshToken),
            'expires_at' => $refreshExpiresAt,
        ]);

        return new TokenPair($accessToken, $accessExpiresAt, $refreshToken, $refreshExpiresAt);
    }

    /**
     * Exchanges a refresh token for a new pair in the same family.
     *
     * @return array{0: User, 1: TokenPair}
     *
     * @throws ApiError TOKEN_INVALID or TOKEN_EXPIRED
     */
    public function rotate(string $refreshToken): array
    {
        $invalid = ApiError::unauthorized('TOKEN_INVALID', 'Refresh token is invalid');

        $token = RefreshToken::where('token_hash', self::hash($refreshToken))->first();
        if ($token === null) {
            throw $invalid;
        }

        if ($token->expires_at->isPast()) {
            throw ApiError::unauthorized('TOKEN_EXPIRED', 'Refresh token expired');
        }

        // The "still unused" check and the update are ONE SQL statement. If two requests race
        // with the same token, the database lets only one of them change the row.
        // The loser gets 0 here and is treated as reuse.
        $consumed = RefreshToken::whereKey($token->id)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['used_at' => now()]);

        if ($consumed === 0) {
            $this->revokeFamily($token->family_id);

            throw $invalid;
        }

        $user = User::find($token->user_id);
        if ($user === null) {
            throw $invalid;
        }

        // The client gets a fresh access token now, so the previous one of this login can go.
        $this->deleteAccessTokens($token->family_id);

        return [$user, $this->issue($user, $token->family_id)];
    }

    /**
     * Ends one login: no refresh token of the family works again, and its access tokens die at once.
     */
    public function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->deleteAccessTokens($familyId);
    }

    /**
     * Finds the family of a refresh token, but only if it belongs to this user.
     */
    public function familyOf(User $user, string $refreshToken): ?string
    {
        return RefreshToken::where('token_hash', self::hash($refreshToken))
            ->where('user_id', $user->id)
            ->value('family_id');
    }

    /**
     * A plain SHA-256 is enough here, unlike passwords: the token is long and random,
     * so there is nothing to guess, and lookups must be fast.
     */
    public static function hash(string $refreshToken): string
    {
        return hash('sha256', $refreshToken);
    }

    private function deleteAccessTokens(string $familyId): void
    {
        PersonalAccessToken::where('name', $familyId)->delete();
    }
}
