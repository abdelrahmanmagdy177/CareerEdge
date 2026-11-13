<?php

namespace App\Support\Idempotency;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * The saved answer to a request that carried an Idempotency-Key.
 *
 * @property string $user_id
 * @property string $key
 * @property string $fingerprint
 * @property int $response_status
 * @property string $response_body
 * @property Carbon $created_at
 */
class IdempotencyKey extends Model
{
    use Prunable;

    public const KEEP_HOURS = 24;

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function isExpired(): bool
    {
        return $this->created_at->lessThanOrEqualTo(now()->subHours(self::KEEP_HOURS));
    }

    /**
     * Rows the daily `model:prune` command deletes.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subHours(self::KEEP_HOURS));
    }
}
