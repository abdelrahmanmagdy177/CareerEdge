<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Enums\SessionMode;
use App\Modules\Scheduling\Enums\SessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRecruitersRequest extends FormRequest
{
    public const SORTS = ['rating_desc', 'price_asc', 'price_desc', 'experience_desc'];

    public function rules(): array
    {
        return [
            'specializationId' => ['bail', 'nullable', 'uuid'],
            'sessionType' => ['bail', 'nullable', Rule::enum(SessionType::class)],
            'mode' => ['bail', 'nullable', Rule::enum(SessionMode::class)],
            'maxPriceMinor' => ['bail', 'nullable', 'integer', 'min:0'],
            'minRating' => ['bail', 'nullable', 'numeric', 'between:1,5'],
            'availableFrom' => ['bail', 'nullable', 'date'],
            'availableTo' => ['bail', 'nullable', 'date', 'after:availableFrom'],
            'q' => ['bail', 'nullable', 'string', 'max:100'],
            'sort' => ['bail', 'nullable', Rule::in(self::SORTS)],
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'pageSize' => ['bail', 'nullable', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'specializationId.*' => 'specializationId must be a specialization id.',
            'sessionType.*' => 'sessionType must be one of: mock_interview, cv_review, salary_consultation.',
            'mode.*' => "mode must be 'chat' or 'voice'.",
            'maxPriceMinor.*' => 'maxPriceMinor must be a whole number of piasters, 0 or more.',
            'minRating.*' => 'minRating must be a number between 1 and 5.',
            'availableFrom.*' => 'availableFrom must be a date and time.',
            'availableTo.*' => 'availableTo must be a date and time after availableFrom.',
            'q.*' => 'q must be at most 100 characters.',
            'sort.*' => 'sort must be one of: '.implode(', ', self::SORTS).'.',
            'page.*' => 'page must be 1 or more.',
            'pageSize.*' => 'pageSize must be between 1 and 100.',
        ];
    }
}
