<?php

namespace App\Modules\Profiles\Http\Resources;

use App\Modules\Profiles\Models\Cv;
use App\Support\Http\ApiDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Cv` schema of docs/openapi.yaml. The storage key is internal and never leaves the server.
 *
 * @mixin Cv
 */
class CvResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fileName' => $this->file_name,
            'contentType' => $this->content_type,
            'sizeBytes' => $this->size_bytes,
            'uploadedAt' => ApiDate::format($this->created_at),
        ];
    }
}
