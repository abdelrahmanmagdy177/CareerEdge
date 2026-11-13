<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\Offering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Offering` schema of docs/openapi.yaml.
 *
 * @mixin Offering
 */
class OfferingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recruiterId' => $this->recruiter_id,
            'sessionType' => $this->session_type->value,
            'mode' => $this->mode->value,
            'durationMinutes' => $this->duration_minutes,
            'price' => $this->price()->toArray(),
            'isActive' => $this->is_active,
        ];
    }
}
