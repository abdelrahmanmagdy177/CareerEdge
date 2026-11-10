<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\UpdateMeRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class MeController
{
    /** GET /me */
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** PATCH /me */
    public function update(UpdateMeRequest $request): UserResource
    {
        $user = $request->user();

        // validated() holds only the keys that were sent. array_key_exists (not isset) matters
        // for phone: an explicit null is a real instruction to clear it.
        $data = $request->validated();

        if (array_key_exists('fullName', $data)) {
            $user->full_name = $data['fullName'];
        }

        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }

        if (array_key_exists('locale', $data)) {
            $user->locale = $data['locale'];
        }

        $user->save();

        return new UserResource($user);
    }
}
