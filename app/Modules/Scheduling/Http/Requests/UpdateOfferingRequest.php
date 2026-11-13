<?php

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** PATCH: only the price and the active flag can change. Type, mode and duration are fixed. */
class UpdateOfferingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            ...StoreOfferingRequest::priceRules(required: false),
            'isActive' => ['bail', 'sometimes', 'required', 'boolean:strict'],
        ];
    }

    public function messages(): array
    {
        return [
            ...StoreOfferingRequest::priceMessages(),
            'isActive.*' => 'isActive must be true or false.',
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['price', 'isActive'])) {
                    $validator->errors()->add('body', 'Send at least one of: price, isActive.');
                }
            },
        ];
    }
}
