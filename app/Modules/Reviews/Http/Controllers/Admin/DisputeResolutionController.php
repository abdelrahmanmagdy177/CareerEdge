<?php

namespace App\Modules\Reviews\Http\Controllers\Admin;

use App\Modules\Reviews\Models\Dispute;
use App\Modules\Scheduling\Contracts\SessionOutcomes;
use App\Support\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DisputeResolutionController
{
    /** GET /admin/disputes?status=: the oldest first, so the queue is worked in order. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['bail', 'nullable', Rule::in([Dispute::OPEN, Dispute::RESOLVED_REFUND, Dispute::RESOLVED_RELEASE])],
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'pageSize' => ['bail', 'nullable', 'integer', 'between:1,100'],
        ], [
            'status.*' => 'status must be open, resolved_refund or resolved_release.',
            'page.*' => 'page must be 1 or more.',
            'pageSize.*' => 'pageSize must be between 1 and 100.',
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);

        $query = Dispute::query()->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status));

        return response()->json([
            'items' => (clone $query)->orderBy('id')->forPage($page, $pageSize)->get()->map->toContract()->values(),
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $query->count(),
        ]);
    }

    /**
     * POST /admin/disputes/{dispute}/resolve
     *
     * refund:  the candidate gets everything back and the session becomes `refunded`.
     * release: the session goes back to `completed` and the recruiter is paid now.
     */
    public function resolve(Request $request, string $dispute, SessionOutcomes $sessions): JsonResponse
    {
        $record = Dispute::findOrFail($dispute);

        $data = $request->validate([
            'resolution' => ['bail', 'required', Rule::in(['refund', 'release'])],
            'note' => ['bail', 'required', 'string', 'min:3', 'max:2000'],
        ], [
            'resolution.*' => "resolution must be 'refund' or 'release'.",
            'note.*' => 'Explain the decision in 3 to 2000 characters.',
        ]);
        $refund = $data['resolution'] === 'refund';
        $alreadyResolved = ApiError::conflict('DISPUTE_ALREADY_RESOLVED', 'This dispute was already resolved');

        DB::transaction(function () use ($record, $data, $refund, $sessions, $alreadyResolved) {
            // "Still open" is part of the UPDATE. If two admins decide at the same moment,
            // only one decision is saved, and money moves once.
            $closed = Dispute::whereKey($record->id)->where('status', Dispute::OPEN)->update([
                'status' => $refund ? Dispute::RESOLVED_REFUND : Dispute::RESOLVED_RELEASE,
                'resolution_note' => $data['note'],
                'resolved_at' => now(),
            ]);

            if ($closed === 0) {
                throw $alreadyResolved;
            }

            // Moves the session out of `disputed` and tells Payments what to do with the money.
            // In the same transaction: either the dispute, the session and the payment all
            // change, or none of them does.
            if (! $sessions->resolveDispute($record->session_id, $refund)) {
                throw $alreadyResolved;
            }
        });

        return response()->json($record->refresh()->toContract());
    }
}
