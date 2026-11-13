<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Enums\SessionMode;
use App\Modules\Scheduling\Enums\SessionType;
use App\Modules\Scheduling\Models\Offering;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfferingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sessionType' => ['bail', 'required', Rule::enum(SessionType::class)],
            'mode' => ['bail', 'required', Rule::enum(SessionMode::class)],
            'durationMinutes' => ['bail', 'required', 'integer', Rule::in(Offering::DURATIONS)],
            ...self::priceRules(required: true),
        ];
    }

    public function messages(): array
    {
        return [
            'sessionType.*' => 'Session type must be one of: mock_interview, cv_review, salary_consultation.',
            'mode.*' => "Mode must be 'chat' or 'voice'.",
            'durationMinutes.*' => 'Duration must be 30, 45 or 60 minutes.',
            ...self::priceMessages(),
        ];
    }

    /**
     * Shared with UpdateOfferingRequest.
     *
     * @return array<string, list<mixed>>
     */
    public static function priceRules(bool $required): array
    {
        return [
            'price' => ['bail', $required ? 'required' : 'sometimes', 'array'],
            // `integer` with strict mode: "45000.5" or a float must not be rounded into a price.
            'price.amountMinor' => ['bail', 'required_with:price', 'integer:strict', 'between:'.Offering::MIN_PRICE_MINOR.','.Offering::MAX_PRICE_MINOR],
            'price.currency' => ['bail', 'required_with:price', Rule::in([Money::DEFAULT_CURRENCY])],
        ];
    }

    /** @return array<string, string> */
    public static function priceMessages(): array
    {
        return [
            'price.*' => 'Price is required, as { amountMinor, currency }.',
            'price.amountMinor.*' => 'Price must be a whole number of piasters between 5000 and 1000000 (50 to 10,000 EGP).',
            'price.currency.*' => 'Currency must be EGP.',
        ];
    }
}
