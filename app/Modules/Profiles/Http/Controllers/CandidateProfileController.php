<?php

namespace App\Modules\Profiles\Http\Controllers;

use App\Modules\Profiles\Http\Requests\CandidateProfileRequest;
use App\Modules\Profiles\Http\Resources\CandidateProfileResource;
use App\Modules\Profiles\Models\CandidateProfile;
use Illuminate\Http\Request;

final class CandidateProfileController
{
    /** GET /me/candidate-profile */
    public function show(Request $request): CandidateProfileResource
    {
        // findOrFail: a candidate who has not onboarded yet gets 404 NOT_FOUND.
        return new CandidateProfileResource(
            CandidateProfile::with('specialization')->findOrFail($request->user()->id),
        );
    }

    /** PUT /me/candidate-profile: creates the profile, or replaces all of it. */
    public function upsert(CandidateProfileRequest $request): CandidateProfileResource
    {
        $data = $request->validated();

        // PUT replaces the whole profile, so an optional field that was left out becomes null.
        $profile = CandidateProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'headline' => $data['headline'] ?? null,
                'specialization_id' => $data['specializationId'],
                'target_role' => $data['targetRole'],
                'target_level' => $data['targetLevel'] ?? null,
                'years_of_experience' => $data['yearsOfExperience'],
            ],
        );

        return new CandidateProfileResource($profile->load('specialization'));
    }
}
