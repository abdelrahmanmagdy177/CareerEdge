<?php

namespace App\Modules\Profiles\Http\Resources;

use App\Modules\Profiles\Models\RecruiterProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `RecruiterProfile` schema of docs/openapi.yaml.
 *
 * The recruiter's name lives in the Identity module, so the caller passes it in.
 *
 * @mixin RecruiterProfile
 */
class RecruiterProfileResource extends JsonResource
{
    public function __construct(RecruiterProfile $profile, private readonly string $fullName)
    {
        parent::__construct($profile);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'userId' => $this->user_id,
            'fullName' => $this->fullName,
            // There is no avatar upload endpoint in the contract yet.
            'avatarUrl' => null,
            'bio' => $this->bio,
            'yearsOfExperience' => $this->years_of_experience,
            'currentTitle' => $this->current_title,
            'linkedinUrl' => $this->linkedin_url,
            'specializations' => SpecializationResource::collection($this->specializations),
            'languages' => $this->languages,
            'timezone' => $this->timezone,
            'verificationStatus' => $this->verification_status->value,
            'verificationNote' => $this->verification_note,
            'ratingAvg' => $this->rating_avg,
            'ratingCount' => $this->rating_count,
            'cancellationCount' => $this->cancellation_count,
        ];
    }
}
