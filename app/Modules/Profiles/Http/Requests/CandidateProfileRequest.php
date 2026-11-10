<?php

namespace App\Modules\Profiles\Http\Requests;

use App\Modules\Profiles\Enums\RoleLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CandidateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'headline' => ['bail', 'nullable', 'string', 'max:150'],
            // `uuid` runs before `exists` on purpose: PostgreSQL throws an error (a 500 for us)
            // if it is asked to compare a uuid column with text that is not a uuid.
            'specializationId' => ['bail', 'required', 'uuid', 'exists:specializations,id'],
            'targetRole' => ['bail', 'required', 'string', 'max:100'],
            'targetLevel' => ['bail', 'nullable', Rule::enum(RoleLevel::class)],
            'yearsOfExperience' => ['bail', 'required', 'integer', 'between:0,50'],
        ];
    }

    public function messages(): array
    {
        return [
            'headline.*' => 'Headline must be at most 150 characters.',
            'specializationId.*' => 'Choose a specialization from the list.',
            'targetRole.*' => 'Target role is required and must be at most 100 characters.',
            'targetLevel.*' => 'Target level must be one of: intern, junior, mid, senior, lead.',
            'yearsOfExperience.*' => 'Years of experience must be a whole number between 0 and 50.',
        ];
    }
}
