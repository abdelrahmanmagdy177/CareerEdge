<?php

namespace App\Modules\Profiles\Http\Controllers;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Http\Requests\RecruiterProfileRequest;
use App\Modules\Profiles\Http\Resources\RecruiterProfileResource;
use App\Modules\Profiles\Models\RecruiterProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RecruiterProfileController
{
    /** GET /me/recruiter-profile */
    public function show(Request $request): RecruiterProfileResource
    {
        $profile = RecruiterProfile::with('specializations')->findOrFail($request->user()->id);

        return new RecruiterProfileResource($profile, $request->user()->full_name);
    }

    /** PUT /me/recruiter-profile: creates the profile, or replaces all of it. */
    public function upsert(RecruiterProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // The profile row and its specialization links change together or not at all.
        $profile = DB::transaction(function () use ($user, $data) {
            $profile = RecruiterProfile::firstOrNew(['user_id' => $user->id]);

            $profile->fill([
                'bio' => $data['bio'],
                'years_of_experience' => $data['yearsOfExperience'],
                'current_title' => $data['currentTitle'] ?? null,
                'linkedin_url' => $data['linkedinUrl'] ?? null,
                'languages' => $data['languages'] ?? ['ar'],
                'timezone' => $data['timezone'],
            ]);

            // New profiles wait for an admin. A rejected recruiter who edits and resubmits goes
            // back in the queue. An approved recruiter stays approved, or every small edit
            // would take them out of search until an admin looked again.
            if (! $profile->exists || $profile->verification_status === VerificationStatus::Rejected) {
                $profile->verification_status = VerificationStatus::Pending;
                $profile->verification_note = null;
            }

            $profile->save();
            $profile->specializations()->sync($data['specializationIds']);

            return $profile;
        });

        // Always 200, as the contract says. Left alone, Laravel answers 201 the first time,
        // because the resource wraps a model that was just created.
        return (new RecruiterProfileResource($profile->load('specializations'), $user->full_name))
            ->response()
            ->setStatusCode(200);
    }
}
