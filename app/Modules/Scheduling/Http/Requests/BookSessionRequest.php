<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the shape of the input is checked here. Whether the offering, slot and CV can really
 * be booked together is decided by SessionBooker.
 */
class BookSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'offeringId' => ['bail', 'required', 'uuid'],
            'slotId' => ['bail', 'required', 'uuid'],
            'cvId' => ['bail', 'nullable', 'uuid'],
            'candidateNotes' => ['bail', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'offeringId.*' => 'offeringId is required and must be an offering id.',
            'slotId.*' => 'slotId is required and must be a slot id.',
            'cvId.*' => 'cvId must be one of your CV ids.',
            'candidateNotes.*' => 'Notes must be at most 1000 characters.',
        ];
    }
}
