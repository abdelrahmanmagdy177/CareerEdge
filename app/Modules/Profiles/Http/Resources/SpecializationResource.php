<?php

namespace App\Modules\Profiles\Http\Resources;

use App\Modules\Profiles\Models\Specialization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Specialization` schema of docs/openapi.yaml.
 *
 * @mixin Specialization
 */
class SpecializationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->localizedName(),
            'parentId' => $this->parent_id,
        ];
    }
}
