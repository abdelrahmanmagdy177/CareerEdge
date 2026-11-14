<?php

namespace App\Modules\Profiles\Services;

use App\Modules\Profiles\Contracts\RecruiterStats;
use App\Modules\Profiles\Models\RecruiterProfile;

final class EloquentRecruiterStats implements RecruiterStats
{
    public function recordRating(string $recruiterId, float $average, int $count): void
    {
        RecruiterProfile::whereKey($recruiterId)->update([
            'rating_avg' => round($average, 2),
            'rating_count' => $count,
        ]);
    }

    public function recordCancellation(string $recruiterId): void
    {
        // increment() is "set x = x + 1" in SQL, so two cancellations at once both count.
        RecruiterProfile::whereKey($recruiterId)->increment('cancellation_count');
    }
}
