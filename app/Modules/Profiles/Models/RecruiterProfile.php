<?php

namespace App\Modules\Profiles\Models;

use App\Modules\Profiles\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $user_id
 * @property string $bio
 * @property int $years_of_experience
 * @property string|null $current_title
 * @property string|null $linkedin_url
 * @property list<string> $languages
 * @property string $timezone
 * @property VerificationStatus $verification_status
 * @property string|null $verification_note
 * @property float $rating_avg
 * @property int $rating_count
 * @property int $cancellation_count
 * @property-read Collection<int, Specialization> $specializations
 */
class RecruiterProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    // The verification and rating columns are deliberately not fillable: a recruiter must not
    // be able to approve or rate themselves by adding fields to the request.
    protected $fillable = ['user_id', 'bio', 'years_of_experience', 'current_title', 'linkedin_url', 'languages', 'timezone'];

    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'verification_status' => VerificationStatus::class,
            'rating_avg' => 'float',
        ];
    }

    /** @return BelongsToMany<Specialization, $this> */
    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(Specialization::class, 'recruiter_specializations', 'recruiter_id', 'specialization_id')
            ->orderBy('slug');
    }
}
