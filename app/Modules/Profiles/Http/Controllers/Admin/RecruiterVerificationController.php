<?php

namespace App\Modules\Profiles\Http\Controllers\Admin;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Http\Resources\RecruiterProfileResource;
use App\Modules\Profiles\Models\RecruiterProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin's side of recruiter verification. Admin endpoints live in the module that owns
 * the data they change, behind the `role:admin` route check.
 */
final class RecruiterVerificationController
{
    public function __construct(private readonly UserDirectory $users) {}

    /** GET /admin/recruiters?verificationStatus=: the review queue, the longest-waiting first. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'verificationStatus' => ['bail', 'nullable', Rule::enum(VerificationStatus::class)],
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'pageSize' => ['bail', 'nullable', 'integer', 'between:1,100'],
        ], [
            'verificationStatus.*' => 'verificationStatus must be pending, approved or rejected.',
            'page.*' => 'page must be 1 or more.',
            'pageSize.*' => 'pageSize must be between 1 and 100.',
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);

        $query = RecruiterProfile::query()
            ->when($filters['verificationStatus'] ?? null, fn ($q, string $status) => $q->where('verification_status', $status));

        $profiles = (clone $query)
            ->with('specializations')
            // updated_at moves when a recruiter resubmits, which puts them at the back of the queue.
            ->orderBy('updated_at')->orderBy('user_id')
            ->forPage($page, $pageSize)
            ->get();

        // All the names in one lookup, not one per row.
        $names = $this->users->summaries($profiles->pluck('user_id')->all());

        return response()->json([
            'items' => $profiles->map(fn (RecruiterProfile $profile) => (new RecruiterProfileResource(
                $profile,
                $names[$profile->user_id]['fullName'],
            ))->resolve())->values(),
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $query->count(),
        ]);
    }

    /** POST /admin/recruiters/{recruiter}/verification: approve or reject. */
    public function decide(Request $request, string $recruiter): JsonResponse
    {
        $profile = RecruiterProfile::with('specializations')->findOrFail($recruiter);

        $data = $request->validate([
            'decision' => ['bail', 'required', Rule::in([VerificationStatus::Approved->value, VerificationStatus::Rejected->value])],
            // A rejected recruiter must be told what to fix, so the note is required then.
            'note' => ['bail', 'required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ], [
            'decision.*' => "decision must be 'approved' or 'rejected'.",
            'note.*' => 'A rejection needs a note of at most 1000 characters telling the recruiter what to fix.',
        ]);

        // Set directly, not through fill(): these columns are deliberately not mass-assignable.
        $profile->verification_status = VerificationStatus::from($data['decision']);
        $profile->verification_note = $data['note'] ?? null;
        $profile->save();

        $name = $this->users->summaries([$profile->user_id])[$profile->user_id]['fullName'];

        return response()->json((new RecruiterProfileResource($profile, $name))->resolve());
    }
}
