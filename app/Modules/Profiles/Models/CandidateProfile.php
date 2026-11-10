<?php

namespace App\Modules\Profiles\Models;

use App\Modules\Profiles\Enums\RoleLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $user_id
 * @property string|null $headline
 * @property string $specialization_id
 * @property string $target_role
 * @property RoleLevel|null $target_level
 * @property int $years_of_experience
 * @property-read Specialization $specialization
 */
class CandidateProfile extends Model
{
    // The primary key is the user's id (a UUID we are given), not an auto-increment number.
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'headline', 'specialization_id', 'target_role', 'target_level', 'years_of_experience'];

    protected function casts(): array
    {
        return [
            'target_level' => RoleLevel::class,
        ];
    }

    /** @return BelongsTo<Specialization, $this> */
    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }
}
