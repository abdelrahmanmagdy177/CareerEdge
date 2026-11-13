<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\AvailabilitySlot;
use App\Support\Http\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Slot` schema of docs/openapi.yaml.
 *
 * @mixin AvailabilitySlot
 */
class SlotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recruiterId' => $this->recruiter_id,
            'startsAt' => ApiDate::format($this->starts_at),
            'endsAt' => ApiDate::format($this->ends_at),
            'status' => $this->status->value,
        ];
    }
}
