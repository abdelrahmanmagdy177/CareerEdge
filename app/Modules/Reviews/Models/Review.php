<?php

namespace App\Modules\Reviews\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $session_id
 * @property string $candidate_id
 * @property string $recruiter_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon $created_at
 */
class Review extends Model
{
    use HasUuids;

    /** A session can be reviewed for this many days after it ended. */
    public const WINDOW_DAYS = 14;

    protected $fillable = ['session_id', 'candidate_id', 'recruiter_id', 'rating', 'comment'];
}
