<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * `bail` stops at the first failed rule of a field, so the client gets one message per field.
     *
     * The email is NOT checked for uniqueness here. A taken email must answer 409 EMAIL_TAKEN,
     * not a validation error, so the controller does that check.
     */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'max:254', 'email'],
            'password' => ['bail', 'required', 'string', 'min:8', 'max:128', 'regex:/\p{L}/u', 'regex:/\d/'],
            'fullName' => ['bail', 'required', ...UserRules::fullName()],
            // Admins are never created through the public API.
            'role' => ['bail', 'required', 'in:candidate,recruiter'],
            'phone' => ['bail', 'nullable', 'string', UserRules::PHONE],
            'locale' => ['bail', 'nullable', 'in:ar,en'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.*' => 'Email is not valid.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be between 8 and 128 characters.',
            'password.max' => 'Password must be between 8 and 128 characters.',
            'password.regex' => 'Password must contain at least one letter and one digit.',
            'fullName.*' => UserRules::FULL_NAME_MESSAGE,
            'role.*' => "Role must be 'candidate' or 'recruiter'.",
            'phone.*' => UserRules::PHONE_MESSAGE,
            'locale.*' => UserRules::LOCALE_MESSAGE,
        ];
    }
}
