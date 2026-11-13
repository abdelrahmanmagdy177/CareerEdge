<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Http\Requests\SearchRecruitersRequest;
use App\Modules\Scheduling\Http\Requests\SlotRangeRequest;
use App\Modules\Scheduling\Http\Resources\OfferingResource;
use App\Modules\Scheduling\Http\Resources\SlotResource;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Modules\Scheduling\Models\Offering;
use App\Modules\Scheduling\Services\RecruiterSearch;
use App\Support\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** What a candidate sees while looking for a recruiter. Only approved recruiters exist here. */
final class RecruiterDiscoveryController
{
    public function __construct(private readonly RecruiterSearch $recruiters) {}

    /** GET /recruiters */
    public function search(SearchRecruitersRequest $request): JsonResponse
    {
        return response()->json($this->recruiters->search($request->validated()));
    }

    /** GET /recruiters/{recruiter} */
    public function show(string $recruiter): JsonResponse
    {
        $profile = $this->recruiters->find($recruiter) ?? throw ApiError::notFound();

        $offerings = Offering::where('recruiter_id', $recruiter)->where('is_active', true)
            ->orderBy('session_type')->orderBy('mode')->orderBy('duration_minutes')
            ->get();

        return response()->json([...$profile, 'offerings' => OfferingResource::collection($offerings)]);
    }

    /** GET /recruiters/{recruiter}/slots?from=&to=: free, future slots that start in the window. */
    public function slots(SlotRangeRequest $request, string $recruiter): AnonymousResourceCollection
    {
        // A pending or rejected recruiter is "not found" here, the same as one that does not exist.
        if ($this->recruiters->find($recruiter) === null) {
            throw ApiError::notFound();
        }

        return SlotResource::collection(
            AvailabilitySlot::where('recruiter_id', $recruiter)
                ->bookable()
                ->where('starts_at', '>=', $request->from())
                ->where('starts_at', '<', $request->to())
                ->orderBy('starts_at')
                ->get(),
        );
    }
}
