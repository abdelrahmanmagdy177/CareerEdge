<?php

namespace App\Modules\Identity\Http\Requests;

/** Field rules from the contract that more than one request uses. */
final class UserRules
{
    /** E.164: "+", then 8 to 15 digits, not starting with 0. */
    public const PHONE = 'regex:/^\+[1-9]\d{7,14}$/';

    public const PHONE_MESSAGE = 'Phone must be in international format, e.g. +201001234567.';

    public const FULL_NAME_MESSAGE = 'Full name must be between 2 and 100 characters.';

    public const LOCALE_MESSAGE = "Locale must be 'ar' or 'en'.";

    /** @return list<string> */
    public static function fullName(): array
    {
        return ['string', 'min:2', 'max:100'];
    }
}
