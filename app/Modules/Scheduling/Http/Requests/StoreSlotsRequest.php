<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\AvailabilitySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSlotsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'slots' => ['bail', 'required', 'array', 'min:1', 'max:50'],
            'slots.*.startsAt' => ['bail', 'required', 'date'],
            'slots.*.endsAt' => ['bail', 'required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'slots.*' => 'Send between 1 and 50 slots.',
            'slots.*.startsAt.*' => 'startsAt must be a date and time, e.g. 2026-11-20T16:00:00Z.',
            'slots.*.endsAt.*' => 'endsAt must be a date and time, e.g. 2026-11-20T17:00:00Z.',
        ];
    }

    /**
     * Rules about the time itself, checked once the dates are known to be readable.
     * Errors are keyed like "slots.2.endsAt", so the app knows which row to mark.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->slots() as $index => [$start, $end]) {
                    if ($start->lessThanOrEqualTo(now())) {
                        $validator->errors()->add("slots.$index.startsAt", 'A slot must start in the future.');

                        continue;
                    }

                    // Signed difference: an end before the start gives a negative number and fails too.
                    $minutes = $start->diffInMinutes($end, absolute: false);
                    if ($minutes < AvailabilitySlot::MIN_MINUTES || $minutes > AvailabilitySlot::MAX_MINUTES) {
                        $validator->errors()->add("slots.$index.endsAt", 'A slot must be between 30 minutes and 4 hours long.');
                    }
                }
            },
        ];
    }

    /**
     * The requested slots as [start, end] pairs in UTC, cut to whole seconds like the database.
     *
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    public function slots(): array
    {
        return array_map(
            fn (array $slot) => [
                CarbonImmutable::parse($slot['startsAt'])->utc()->startOfSecond(),
                CarbonImmutable::parse($slot['endsAt'])->utc()->startOfSecond(),
            ],
            array_values($this->input('slots', [])),
        );
    }
}
