<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Enums\SessionMode;
use App\Modules\Scheduling\Enums\SessionStatus;
use App\Modules\Scheduling\Enums\SessionType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One booked session between a candidate and a recruiter.
 *
 * @property string $id
 * @property string $candidate_id
 * @property string $recruiter_id
 * @property string $offering_id
 * @property string|null $slot_id
 * @property string|null $cv_id
 * @property string|null $specialization_id
 * @property SessionType $type
 * @property SessionMode $mode
 * @property int $duration_minutes
 * @property int $price_minor
 * @property string $currency
 * @property Carbon $scheduled_start
 * @property Carbon $scheduled_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property SessionStatus $status
 * @property string|null $candidate_notes
 * @property Carbon|null $hold_expires_at
 * @property Carbon|null $confirmed_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon $created_at
 */
class Session extends Model
{
    use HasUuids;

    /** How long a candidate has to pay before the slot is given back. */
    public const HOLD_MINUTES = 15;

    /** A session can be joined from this many minutes before it starts. */
    public const JOIN_EARLY_MINUTES = 10;

    protected $table = 'session_bookings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => SessionType::class,
            'mode' => SessionMode::class,
            'status' => SessionStatus::class,
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function price(): Money
    {
        return new Money($this->price_minor, $this->currency);
    }

    /**
     * Sessions that still take up someone's time (everything except cancelled, no-show, refunded).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOccupying(Builder $query): void
    {
        $query->whereNotIn('status', SessionStatus::releasedValues());
    }

    /**
     * What this user may see: a candidate their own sessions, a recruiter the paid sessions
     * booked with them, an admin everything.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, string $userId, string $role): void
    {
        match ($role) {
            'admin' => null,
            // Not before payment: until then it is only a candidate's attempt, and it would
            // show the recruiter a CV and notes for a session that may never happen.
            'recruiter' => $query->where('recruiter_id', $userId)->whereNotNull('confirmed_at'),
            default => $query->where('candidate_id', $userId),
        };
    }
}
