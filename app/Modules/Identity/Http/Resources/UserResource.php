<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Contracts\ProfileLookup;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `User` schema of docs/openapi.yaml. The model uses snake_case columns,
 * the contract uses camelCase, and this class is the only place that maps between them.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'fullName' => $this->full_name,
            'role' => $this->role->value,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'hasProfile' => app(ProfileLookup::class)->hasProfile($this->resource),
            'createdAt' => ApiDate::format($this->created_at),
        ];
    }
}
