<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Enums\SessionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSessionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['bail', 'nullable', 'array'],
            'status.*' => ['bail', Rule::enum(SessionStatus::class)],
            'scope' => ['bail', 'nullable', Rule::in(['upcoming', 'past', 'all'])],
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'pageSize' => ['bail', 'nullable', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        $statuses = implode(', ', array_column(SessionStatus::cases(), 'value'));

        return [
            'status.*' => "status must be a comma-separated list of: $statuses.",
            'scope.*' => 'scope must be upcoming, past or all.',
            'page.*' => 'page must be 1 or more.',
            'pageSize.*' => 'pageSize must be between 1 and 100.',
        ];
    }

    /** The contract sends statuses as one comma-separated value: ?status=confirmed,completed */
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('status'))) {
            $this->merge(['status' => array_values(array_filter(array_map('trim', explode(',', $this->query('status')))))]);
        }
    }

    /** Errors on one item ("status.1") are reported under "status". */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): never
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            $errors[explode('.', $field)[0]] = [$messages[0]];
        }

        throw \Illuminate\Validation\ValidationException::withMessages($errors);
    }
}
