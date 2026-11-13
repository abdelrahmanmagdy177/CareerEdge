<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Http\Requests\SlotRangeRequest;
use App\Modules\Scheduling\Http\Requests\StoreSlotsRequest;
use App\Modules\Scheduling\Http\Resources\SlotResource;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Support\Http\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class SlotController
{
    /** PostgreSQL's error code for a broken exclusion constraint (our "no overlapping slots" rule). */
    private const EXCLUSION_VIOLATION = '23P01';

    /** GET /me/slots?from=&to=: my slots that touch the window, in every status. */
    public function index(SlotRangeRequest $request): AnonymousResourceCollection
    {
        return SlotResource::collection(
            AvailabilitySlot::where('recruiter_id', $request->user()->id)
                ->overlapping($request->from(), $request->to())
                ->orderBy('starts_at')
                ->get(),
        );
    }

    /** POST /me/slots: creates all the slots, or none of them. */
    public function store(StoreSlotsRequest $request): JsonResponse
    {
        $recruiterId = $request->user()->id;
        $slots = $request->slots();
        $overlap = ApiError::conflict('SLOT_OVERLAP', 'A slot overlaps another one of your slots');

        if ($this->overlapEachOther($slots)) {
            throw $overlap;
        }

        // The friendly check: one query that asks whether any existing slot overlaps any new one.
        $clash = AvailabilitySlot::where('recruiter_id', $recruiterId)
            ->where(function ($query) use ($slots) {
                foreach ($slots as [$start, $end]) {
                    $query->orWhere(fn ($q) => $q->overlapping($start, $end));
                }
            })
            ->exists();

        if ($clash) {
            throw $overlap;
        }

        try {
            $created = DB::transaction(fn () => array_map(
                fn (array $slot) => AvailabilitySlot::create([
                    'recruiter_id' => $recruiterId,
                    'starts_at' => $slot[0],
                    'ends_at' => $slot[1],
                ]),
                $slots,
            ));
        } catch (QueryException $e) {
            // The real guarantee. Another request added an overlapping slot between our check
            // and our insert, and the database constraint refused ours.
            if ($e->getCode() === self::EXCLUSION_VIOLATION) {
                throw $overlap;
            }

            throw $e;
        }

        return SlotResource::collection($created)->response()->setStatusCode(201);
    }

    /** DELETE /me/slots/{slot}: only a slot nobody has booked. */
    public function destroy(Request $request, string $slot): Response
    {
        $record = AvailabilitySlot::where('recruiter_id', $request->user()->id)->findOrFail($slot);

        // "Still open" is part of the DELETE itself. If a candidate books the slot in the instant
        // between reading it and deleting it, this deletes nothing and we answer 409.
        $deleted = AvailabilitySlot::whereKey($record->id)->where('status', SlotStatus::Open)->delete();

        if ($deleted === 0) {
            throw ApiError::conflict('SLOT_UNAVAILABLE', 'This slot is held or booked. Cancel the session instead');
        }

        return response()->noContent();
    }

    /**
     * Do any two of the requested slots overlap each other? After sorting by start time, it is
     * enough to compare each slot with the one before it.
     *
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $slots
     */
    private function overlapEachOther(array $slots): bool
    {
        usort($slots, fn (array $a, array $b) => $a[0] <=> $b[0]);

        for ($i = 1; $i < count($slots); $i++) {
            if ($slots[$i][0]->lessThan($slots[$i - 1][1])) {
                return true;
            }
        }

        return false;
    }
}
