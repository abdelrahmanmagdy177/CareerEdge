<?php

namespace App\Modules\Profiles\Http\Controllers;

use App\Modules\Profiles\Http\Resources\SpecializationResource;
use App\Modules\Profiles\Models\Specialization;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SpecializationController
{
    /** GET /specializations: a flat list. The client builds the tree with `parentId`. */
    public function index(): AnonymousResourceCollection
    {
        // Parents first, then alphabetical, so the order is stable between requests.
        return SpecializationResource::collection(
            Specialization::orderByRaw('parent_id is not null')->orderBy('slug')->get(),
        );
    }
}
