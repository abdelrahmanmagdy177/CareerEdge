<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One refresh token. Only its hash is stored.
 *
 * Tokens created by refreshing each other share a `family_id`, so the whole chain
 * from one login can be revoked at once.
 *
 * @property string $id
 * @property string $user_id
 * @property string $family_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at Set when the token was exchanged for a new one. A used token must never work again.
 * @property Carbon|null $revoked_at Set on logout, or when reuse of a token in this family was detected.
 */
class RefreshToken extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'family_id', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
