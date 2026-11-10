<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Body of both POST /auth/refresh and POST /auth/logout. */
class RefreshTokenRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'refreshToken' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'refreshToken.*' => 'Refresh token is required.',
        ];
    }
}
