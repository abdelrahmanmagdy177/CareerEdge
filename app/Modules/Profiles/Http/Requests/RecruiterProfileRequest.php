<?php

namespace App\Modules\Profiles\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RecruiterProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bio' => ['bail', 'required', 'string', 'min:50', 'max:2000'],
            'yearsOfExperience' => ['bail', 'required', 'integer', 'between:1,50'],
            'currentTitle' => ['bail', 'nullable', 'string', 'max:100'],
            // Only web links. Without the scheme list, "javascript:..." would pass as a URL
            // and become a clickable link in the app.
            'linkedinUrl' => ['bail', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'specializationIds' => ['bail', 'required', 'array', 'min:1', 'max:5'],
            'specializationIds.*' => ['bail', 'uuid', 'distinct', 'exists:specializations,id'],
            'languages' => ['bail', 'nullable', 'array', 'min:1'],
            'languages.*' => ['bail', 'distinct', 'in:ar,en'],
            'timezone' => ['bail', 'required', 'string', 'timezone:all'],
        ];
    }

    public function messages(): array
    {
        return [
            'bio.*' => 'Bio must be between 50 and 2000 characters.',
            'yearsOfExperience.*' => 'Years of experience must be a whole number between 1 and 50.',
            'currentTitle.*' => 'Current title must be at most 100 characters.',
            'linkedinUrl.*' => 'LinkedIn URL must be a valid http or https link.',
            'specializationIds.*' => 'Choose between 1 and 5 different specializations from the list.',
            'languages.*' => "Languages must be a list containing 'ar', 'en' or both.",
            'timezone.*' => 'Timezone must be an IANA name, e.g. Africa/Cairo.',
        ];
    }

    /**
     * Errors on list items are reported as "specializationIds.0", "languages.1" and so on.
     * The client only has one input per list, so fold them into the list's own key.
     */
    protected function failedValidation(Validator $validator): never
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            $errors[explode('.', $field)[0]] = [$messages[0]];
        }

        throw ValidationException::withMessages($errors);
    }
}
