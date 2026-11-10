<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMeRequest extends FormRequest
{
    private const FIELDS = ['fullName', 'phone', 'locale'];

    /**
     * `sometimes` means "validate only if the key was sent". That is what makes this a PATCH:
     * a field that is absent is left alone, while `phone: null` is present and clears the phone.
     */
    public function rules(): array
    {
        return [
            'fullName' => ['bail', 'sometimes', 'required', ...UserRules::fullName()],
            'phone' => ['bail', 'sometimes', 'nullable', 'string', UserRules::PHONE],
            'locale' => ['bail', 'sometimes', 'required', 'in:ar,en'],
        ];
    }

    public function messages(): array
    {
        return [
            'fullName.*' => UserRules::FULL_NAME_MESSAGE,
            'phone.*' => UserRules::PHONE_MESSAGE,
            'locale.*' => UserRules::LOCALE_MESSAGE,
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('body', 'Send at least one of: fullName, phone, locale.');
                }
            },
        ];
    }
}
