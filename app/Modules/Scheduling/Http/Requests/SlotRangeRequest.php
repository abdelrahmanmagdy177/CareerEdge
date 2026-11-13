<?php

namespace App\Modules\Scheduling\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The `?from=...&to=...` window used when listing slots. At most 31 days, so a list stays small. */
class SlotRangeRequest extends FormRequest
{
    public const MAX_DAYS = 31;

    public function rules(): array
    {
        return [
            'from' => ['bail', 'required', 'date'],
            'to' => ['bail', 'required', 'date', 'after:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.*' => 'from is required and must be a date and time.',
            'to.*' => 'to is required and must be a date and time after from.',
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->from()->addDays(self::MAX_DAYS)->lessThan($this->to())) {
                    $validator->errors()->add('to', 'The range can be at most 31 days.');
                }
            },
        ];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->query('from'))->utc();
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->query('to'))->utc();
    }
}
