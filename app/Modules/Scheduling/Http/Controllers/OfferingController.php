<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Http\Requests\StoreOfferingRequest;
use App\Modules\Scheduling\Http\Requests\UpdateOfferingRequest;
use App\Modules\Scheduling\Http\Resources\OfferingResource;
use App\Modules\Scheduling\Models\Offering;
use App\Support\Http\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class OfferingController
{
    /** GET /me/offerings: all of mine, active and inactive. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return OfferingResource::collection(
            Offering::where('recruiter_id', $request->user()->id)
                ->orderByDesc('is_active')->orderBy('session_type')->orderBy('mode')->orderBy('duration_minutes')->orderBy('id')
                ->get(),
        );
    }

    /** POST /me/offerings */
    public function store(StoreOfferingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $offering = new Offering([
            'recruiter_id' => $request->user()->id,
            'session_type' => $data['sessionType'],
            'mode' => $data['mode'],
            'duration_minutes' => $data['durationMinutes'],
            'price_minor' => $data['price']['amountMinor'],
            'currency' => $data['price']['currency'],
        ]);

        $this->save($offering);

        return (new OfferingResource($offering))->response()->setStatusCode(201);
    }

    /** PATCH /me/offerings/{offering}: change the price, or switch the offering on or off. */
    public function update(UpdateOfferingRequest $request, string $offering): OfferingResource
    {
        $record = Offering::where('recruiter_id', $request->user()->id)->findOrFail($offering);
        $data = $request->validated();

        if (isset($data['price'])) {
            // Sessions that are already booked keep the price they were booked at. They copy
            // the price when they are created and never read it from here again.
            $record->price_minor = $data['price']['amountMinor'];
            $record->currency = $data['price']['currency'];
        }

        if (array_key_exists('isActive', $data)) {
            $record->is_active = $data['isActive'];
        }

        $this->save($record);

        return new OfferingResource($record);
    }

    /**
     * Saves, and turns the database's "one active offering per type, mode and duration" rule
     * into the contract's 409.
     */
    private function save(Offering $offering): void
    {
        try {
            // The transaction matters when this runs inside another one (as in tests): a failed
            // statement poisons a PostgreSQL transaction, and this gives it a savepoint to
            // roll back to.
            DB::transaction(fn () => $offering->save());
        } catch (UniqueConstraintViolationException) {
            throw ApiError::conflict('OFFERING_EXISTS', 'You already have an active offering with this type, mode and duration');
        }
    }
}
