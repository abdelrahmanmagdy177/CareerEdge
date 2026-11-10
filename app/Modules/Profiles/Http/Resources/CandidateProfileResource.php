<?php

namespace App\Modules\Profiles\Http\Resources;

use App\Modules\Profiles\Models\CandidateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `CandidateProfile` schema of docs/openapi.yaml.
 *
 * @mixin CandidateProfile
 */
class CandidateProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'userId' => $this->user_id,
            'headline' => $this->headline,
            'specializationId' => $this->specialization_id,
            'specialization' => new SpecializationResource($this->specialization),
            'targetRole' => $this->target_role,
            'targetLevel' => $this->target_level?->value,
            'yearsOfExperience' => $this->years_of_experience,
        ];
    }
}
