<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Enums\SlotStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A stretch of time in which a recruiter can take a session.
 *
 * @property string $id
 * @property string $recruiter_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property SlotStatus $status
 */
class AvailabilitySlot extends Model
{
    use HasUuids;

    public const MIN_MINUTES = 30;

    public const MAX_MINUTES = 240;

    protected $fillable = ['recruiter_id', 'starts_at', 'ends_at', 'status'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => SlotStatus::class,
        ];
    }

    /**
     * Slots that share any moment with [start, end). Two ranges overlap exactly when each one
     * starts before the other ends. Touching ends (one ends at 17:00, the next starts at 17:00)
     * do not count.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }

    /**
     * What a candidate can book: free, and not started yet.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBookable(Builder $query): void
    {
        $query->where('status', SlotStatus::Open)->where('starts_at', '>', now());
    }
}
